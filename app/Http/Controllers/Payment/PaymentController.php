<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Classes\Payment\PaymentFactory;
use App\Http\Requests\Payment\DepositRequest;
use App\Http\Requests\Payment\PayContributionRequest;
use App\Http\Requests\Payment\WithdrawRequest;
use App\Models\BankCard;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ContributionPaymentSuccessNotification;
use App\Notifications\GenericNotification;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Frontend: pay contribution for a group.
     * Body: { group_id, amount, provider, reference?, use_wallet?(bool) }
     */
    public function payContribution(PayContributionRequest $request, PaymentService $service)
    {
        $data = $request->validated();
        // Log::info($data);

        $user = User::find($data['user_id']);
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $group = Group::find($data['group_id']);

        try {
            $tx = $service->payContribution(
                $user,
                $group,
                (float) $data['amount'],
                $data['provider'] ?? null,
                $data['reference'] ?? null,
                !empty($data['use_wallet'])
            );
            $user->notify(new ContributionPaymentSuccessNotification($tx, $group, 'success'));


            return response()->json(['ok' => true, 'transaction' => $tx]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Deposit funds to wallet. Can be from card (provider) or from pending_wallet transfer.
     * Body: { amount, provider, reference?, from_pending?(bool) }
     */
    public function deposit(DepositRequest $request, PaymentService $service)
    {
        $data = $request->validated();
        $user = User::find($data['user_id']);
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }


        try {
            $tx = $service->deposit(
                $user,
                (float) $data['amount'],
                $data['method'],
                $data['provider'] ?? null,
                $data['reference'] ?? null,
                !empty($data['from_pending']),
                [
                    "card_id" => $data['card_id'] ?? null,
                    "user_id" => $data['user_id'] ?? null,
                ]
            );

            // Log::info(["res" => $tx]);p
            

            return response()->json(['ok' => true, 'transaction' => $tx]);
        } catch (\Throwable $e) {
            Log::info($e);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Withdraw: request to withdraw from available wallet to external source (bank/card)
     * Body: { amount, reference? }
     */
    public function withdraw(WithdrawRequest $request, PaymentService $service)
    {
        $data = $request->validated();

        $user = User::find($data['user_id']);

        try {
            $tx = $service->withdraw($user, (float) $data['amount'], $data['reference'] ?? null, $data);
            return response()->json(['ok' => true, 'transaction' => $tx]);
        } catch (\Throwable $e) {
            Log::alert($e);
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }


    public function verifyCardPayment(Request $request, PaymentService $service)
    {
        // validate input
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'card_id' => 'nullable|exists:bank_cards,id',
            'transaction_reference' => 'required|exists:transactions,reference',
            'otp' => 'nullable|string',
            'pin' => 'nullable|string',
        ]);

        try {
            // load user (fail fast if not found)
            $user = User::findOrFail($data['user_id']);

            // load card only when card_id provided
            $card = null;
            if (!empty($data['card_id'])) {
                $card = BankCard::findOrFail($data['card_id']);
            }

            // load transaction by reference
            $transaction = Transaction::where('reference', $data['transaction_reference'])->firstOrFail();

            // Build user/card payloads to send to the service.
            // If your models implement a toResource() helper that returns a Laravel Resource,
            // convert it using the current $request. Otherwise fall back to ->toArray().
            $userPayload = null;
            if (method_exists($user, 'toResource')) {
                $userPayload = $user->toResource()->toArray($request);
            } else {
                $userPayload = $user->toArray();
            }

            $cardPayload = null;
            if ($card) {
                if (method_exists($card, 'toResource')) {
                    $cardPayload = $card->toResource()->toArray($request);
                } else {
                    $cardPayload = $card->toArray();
                }
            }

            $payload = [
                'user' => $userPayload,
                'card' => $cardPayload,
                'pin' => $data['pin'] ?? null,
                'otp' => $data['otp'] ?? null,
                // pass provider reference / charge id so provider can call authorize
                'charge_id' => $transaction->provider_reference ?? null,
            ];

            // call service
            $response = $service->verifyCardPayment($payload);

            return response()->json([
                'ok' => true,
                'transaction' => $response,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // one of the findOrFail calls failed
            return response()->json(['ok' => false, 'message' => 'Resource not found.'], 404);
        } catch (\Throwable $e) {
            // unexpected error
            return response()->json(['ok' => false, 'message' => 'An error occurred.'], 500);
        }
    }

    function banks(){
        return (new PaymentService())->listBanks();
    }

    function verifyBankAccount(Request $request){
        $data = $request->validate([
            "code" => "required|string",
            "number" => "required|string"
        ]);
        return (new PaymentService())->verifyBankAccount($data);
    }
}
