<?php

namespace App\Http\Controllers\Payment;

use App\Classes\ExpoSdkPushService ;
use App\Http\Controllers\Controller;
use App\Classes\Payment\PaymentFactory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\ExpoPushToken;
use App\Notifications\TransactionNotification;

use App\Jobs\SendExpoSdkPushJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WebhookController extends Controller
{
    protected ExpoSdkPushService $expoService;

    public function __construct(ExpoSdkPushService $expoService)
    {
        $this->expoService = $expoService;
    }

    /**
     * Generic webhook entry. Provider should be passed as segment.
     */
    public function handle(Request $request, $provider)
    {
        try {
            $rawBody = (string) $request->getContent();
            $payload = $request->json()->all() ?? $request->all();

            // Resolve provider instance
            $providerInstance = PaymentFactory::provider($provider);

            // Optional signature verification (provider-specific env var like FLW_WEBHOOK_SECRET)
            $secretEnv = strtoupper($provider) . '_WEBHOOK_SECRET';
            $secret = env($secretEnv);
            if ($secret) {
                $signatureHeader = $request->header('x-flw-signature') ?? $request->header('x-signature') ?? $request->header('signature') ?? null;
                if ($signatureHeader) {
                    $computed = hash_hmac('sha256', $rawBody, $secret);
                    if (!hash_equals($computed, $signatureHeader)) {
                        Log::warning("Webhook signature verification failed for provider {$provider}", [
                            'computed' => $computed,
                            'header' => $signatureHeader,
                        ]);
                        return response()->json(['ok' => false, 'error' => 'invalid signature'], 400);
                    }
                }
            }

            // Let provider parse/normalize the webhook payload
            $result = $providerInstance->handleWebhook($payload);

            // Safely get reference (try result first, then payload)
            $reference = data_get($result, 'reference', data_get($payload, 'reference', null));
            $providerWebhookType = data_get($result, 'provider_webhook_type', data_get($payload, 'provider_webhook_type', null));
            $statusFromProvider = (string) data_get($result, 'status', data_get($payload, 'status', ''));

            // We'll write a raw dump to storage for debugging — keep filenames deterministic & safe
            $filenameRef = $reference ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $reference) : 'no_reference';
            $dumpPath = "webhook/{$provider}/{$filenameRef}_" . time() . ".json";

            // Use DB transaction when mutating DB
            DB::beginTransaction();

            $tx = null;
            if ($reference) {
                $tx = Transaction::where('reference', $reference)->first();
            }

            $amt = (float) data_get($result, 'amount', 0);

            // If no transaction found, handle special bank_transfer case (your existing logic)
            if (! $tx) {
                Log::info("Webhook: transaction not found for reference {$reference}", ['provider' => $provider, 'result' => $result]);

                // previous code credited wallet when payment_type is bank_transfer
                $paymentType = data_get($result, 'raw.data.payment_type') ?: data_get($result, 'payment_type');
                $customerId = data_get($result, 'customer.id') ?: data_get($payload, 'customer.id');

                if ($paymentType === 'bank_transfer' && $customerId) {
                    $user = User::find($customerId);
                    if ($user && $amt > 0) {
                        // Use your wallet-crediting logic (wrap in try/catch)
                        try {
                            $user->creditToWallet($amt, $result);
                            Log::info("Credited user {$user->id} wallet with {$amt} for bank transfer (no existing tx)", ['reference' => $reference]);
                        } catch (\Throwable $e) {
                            Log::warning("Failed to credit wallet for user {$customerId}: " . $e->getMessage());
                        }
                    } else {
                        Log::warning("Bank transfer webhook: customer not found or zero amount", ['customerId' => $customerId, 'amount' => $amt]);
                    }
                }

                // persist debug dump and commit (no tx to update)
                Storage::put($dumpPath, json_encode($payload, JSON_PRETTY_PRINT));
                DB::commit();

                return response()->json(['ok' => true, 'handled' => true, 'note' => 'no_transaction_found']);
            }

            // Update transaction status mapping
            $statusNormalized = strtolower($statusFromProvider);
            if (in_array($statusNormalized, ['success', 'completed', 'paid'])) {
                $tx->status = Transaction::STATUS_SUCCESS;
            } elseif (in_array($statusNormalized, ['failed', 'error'])) {
                $tx->status = Transaction::STATUS_FAILED;
            } else {
                // keep provider-provided status if unknown
                $tx->status = $statusFromProvider;
            }

            // Merge meta safely (ensure $tx->meta is array)
            $existingMeta = is_array($tx->meta) ? $tx->meta : (is_string($tx->meta) ? json_decode($tx->meta, true) ?? [] : []);
            $tx->meta = array_merge($existingMeta, [
                'webhook_payload' => $payload,
                'provider_result' => $result,
            ]);

            $tx->save();

            // Transaction notification to user (Laravel Notification)
            try {
                $user = $tx->user;
                if ($user) {
                    $notifType = $tx->status === Transaction::STATUS_SUCCESS
                        ? 'success'
                        : ($tx->status === Transaction::STATUS_FAILED ? 'failed' : 'pending');

                    // Fire standard Laravel notification (your existing notification)
                    $user->notify(new TransactionNotification($tx, $notifType, ['provider_result' => $result]));

                    // --- Expo push notifications: gather tokens and push asynchronously if possible ---
                    $tokens = ExpoPushToken::where('user_id', $user->id)->pluck('token')->toArray();
                    if (!empty($tokens)) {
                        // Build simple messages (SDK accepts arrays or ExpoMessage instances)
                        $messages = array_map(function ($token) use ($tx, $notifType) {
                            return [
                                'to' => $token,
                                'title' => $notifType === 'success' ? 'Payment received' : ($notifType === 'failed' ? 'Payment failed' : 'Payment update'),
                                'body' => "Transaction {$tx->reference}: {$tx->status}",
                                'data' => [
                                    'transaction_id' => $tx->id,
                                    'reference' => $tx->reference,
                                    'status' => $tx->status,
                                ],
                            ];
                        }, $tokens);

                        // If SendExpoSdkPushJob exists, dispatch job (preferred). Otherwise call service synchronously.
                        SendExpoSdkPushJob::dispatch($messages, $tokens);
                    }
                }
            } catch (\Throwable $ex) {
                Log::warning('Failed to send transaction notification or push: ' . $ex->getMessage(), [
                    'tx_id' => $tx->id ?? null,
                ]);
            }

            // If provider indicates "charge.completed" and transaction successful, increment wallet
            if ($providerWebhookType === 'charge.completed' && $tx->status === Transaction::STATUS_SUCCESS) {
                try {
                    $user = $tx->user;
                    if ($user && $amt > 0) {
                        // Use increment or your own wallet method
                        if($tx->type === Transaction::TYPE_TOPUP){
                            $user->increment('available_wallet', $amt);
                            Log::info("Credited user {$user->id} wallet with {$amt} from transaction {$tx->id}");
                        }
                        elseif($tx->type === Transaction::TYPE_CHARGE && $tx->group_id){
                            $group = $tx->group;
                            if($group){
                                $group->increment('saved', $amt);
                                $group->users()->updateExistingPivot($user->id, [
                                    'contributed' => DB::raw("COALESCE(contribute,0) + {$amt}"),
                                    'total_contributed' => DB::raw("COALESCE(total_contributed,0) + {$amt}"),
                                    'last_payment_at' => now(),
                                ]);
                            }

                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to credit user wallet after charge.completed: " . $e->getMessage());
                }
            }

            // Save debug dump and commit
            Storage::put($dumpPath, json_encode($payload, JSON_PRETTY_PRINT));
            DB::commit();

            return response()->json(['ok' => true, 'handled' => true]);
        } catch (\Throwable $e) {
            // Rollback any transaction if started
            try { DB::rollBack(); } catch (\Throwable $_) {}
            // Log full context for debugging
            Log::error('Webhook handler exception', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // rethrow or return 500 — choose to return 500 safely
            return response()->json(['ok' => false, 'error' => 'internal_server_error'], 500);
        }
    }
}
