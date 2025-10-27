<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Classes\Payment\PaymentFactory;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TransactionNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Generic webhook entry. Provider should be passed as segment.
     */
    public function handle(Request $request, $provider)
    {
        try {
            
            $payload = $request->all();
            $providerInstance = PaymentFactory::provider($provider);
            $secret = env(strtoupper($provider) . '_WEBHOOK_SECRET');
            if ($secret) {
                $signatureHeader = $request->header('x-flw-signature') ?? $request->header('x-signature') ?? null;
                if ($signatureHeader) {
                    // compute HMAC SHA256 of raw payload
                    $raw = $request->getContent();
                    $computed = hash_hmac('sha256', $raw, $secret);
                    if (!hash_equals($computed, $signatureHeader)) {
                        Log::warning("Webhook signature verification failed for provider {$provider}");
                        return response()->json(['ok' => false, 'error' => 'invalid signature'], 400);
                    }
                }
            }

            $result = $providerInstance->handleWebhook($payload);

            // attempt to find transaction by reference and update status
            $reference = $result['reference'] ?? ($payload['reference'] ?? null);

            if ($reference) {
                $tx = Transaction::where('reference', $reference)->first();
                $amt = isset($result['amount']) ? (float) $result['amount'] : 0;

                if(!$tx){
                    Log::info($result);
                    if($result['raw']['data']['payment_type'] === "bank_transfer"){
                        $user = User::find($result['customer']['id']);
                        $user->creditToWallet($amt, $result);
                    }
                };

                $status = $result['status'];

                if (in_array(strtolower($status), ['success', 'completed', 'paid'])) {
                    $tx->status = Transaction::STATUS_SUCCESS;
                } elseif (in_array(strtolower($status), ['failed', 'error', 'failed'])) {
                    $tx->status = Transaction::STATUS_FAILED;
                } else {
                    $tx->status = $status;
                }
                $tx->meta = array_merge($tx->meta ?? [], ['webhook' => $payload, 'provider_result' => $result]);
                $tx->save();

                // send notification to user about transaction status change
                try {
                    $user = $tx->user;
                    if ($user) {
                        $notifType = $tx->status === Transaction::STATUS_SUCCESS ? 'success' : ($tx->status === Transaction::STATUS_FAILED ? 'failed' : 'pending');
                        $user->notify(new TransactionNotification($tx, $notifType, ['provider_result' => $result]));
                    }
                } catch (\Throwable $ex) {
                    Log::warning('Failed to send transaction notification: ' . $ex->getMessage());
                }

                // If completed and credit to user wallet for incoming funds
                if(in_array($result['provider_webhook_type'], ['charge.completed'])){
                    if($tx->status === Transaction::STATUS_SUCCESS){
                        $user = $tx->user;
                        if(!$user) return;
                        if($amt <= 0 ) return;
                        $user->increment("available_wallet", $amt);
                        Log::info("Credited user {$user->id} wallet with {$amt} from transaction {$tx->id}");
                    }

                }

            }

            return response()->json(['ok' => true, 'handled' => $result]);
    
        } catch (\Throwable $e) {
           
            throw $e;
        }
    }
}
