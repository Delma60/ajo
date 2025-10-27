<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ReferralService
{
    // threshold in NGN for referred user's spend to qualify referrer
    public const QUALIFY_THRESHOLD = 2000; // NGN
    public const REFERRAL_REWARD = 1000; // NGN

    public function generateCode(User $user): string
    {
        // simple code: MT-<USERID>-<RAND4>
        $code = strtoupper('MT-' . $user->id . '-' . Str::upper(Str::random(4)));
        // ensure unique
        if (Referral::where('code', $code)->exists()) return $this->generateCode($user);
        $r = Referral::create(['referrer_id' => $user->id, 'code' => $code]);
        ReferralEvent::create(['referral_id' => $r->id, 'type' => 'created']);
        return $code;
    }

    public function acceptReferral(string $code, User $referred): ?Referral
    {
        $ref = Referral::where('code', $code)->first();
        if (!$ref) return null;

        // avoid self-referral
        if ($ref->referrer_id === $referred->id) return null;
        // $referrer = User::find($ref->referrer_id);
        $ref->referrer->increment("pending_referral", self::REFERRAL_REWARD);

        $ref->referred_id = $referred->id;
        $ref->accepted_at = now();
        $ref->save();

        ReferralEvent::create(['referral_id' => $ref->id, 'type' => 'signup', 'meta' => ['referred_id' => $referred->id]]);
        return $ref;
    }

    /**
     * Record contribution made by a user; if user was referred, accumulate their successful contributions and
     * when they reach QUALIFY_THRESHOLD, credit referrer's pending_referral with REFERRAL_REWARD.
     */
    public function recordContribution(Transaction $tx): void
    {
        if (!$tx->isSuccess()) return;
        $user = $tx->user;
        if (!$user) return;

        // find a referral where this user is the referred
        // lock the referral row for update when present to avoid races when crediting
        $ref = Referral::where('referred_id', $user->id)->first();
        if (!$ref) return;

        // create event
        ReferralEvent::create(['referral_id' => $ref->id, 'transaction_id' => $tx->id, 'type' => 'contribution', 'meta' => ['amount' => (float)$tx->amount]]);

        // compute total successful contributions after signup
        $total = Transaction::where('user_id', $user->id)->where('status', Transaction::STATUS_SUCCESS)->sum('amount');

        if ($total < self::QUALIFY_THRESHOLD) return;

        // Atomically check-and-set credited_at on the referral to avoid double-crediting in races
        DB::transaction(function() use ($ref) {
            // re-fetch for update
            $locked = Referral::where('id', $ref->id)->lockForUpdate()->first();
            if (!$locked) return;

            // if already credited, nothing to do
            if ($locked->credited_at) return;

            // mark credited_at
            $locked->credited_at = now();
            $locked->save();

            // credit referrer: increment pending_referral on user model
            $referrer = User::find($locked->referrer_id);
            if (!$referrer) return;
            $referrer->pending_referral = ($referrer->pending_referral ?? 0) + self::REFERRAL_REWARD;
            $referrer->save();

            ReferralEvent::create(['referral_id' => $locked->id, 'type' => 'credited', 'meta' => ['amount' => self::REFERRAL_REWARD]]);
        });
    }

    /**
     * Payout referral: move pending_referral to available_referral (called when performing payout)
     */
    public function payoutReferrer(User $user, float $amount): bool
    {
        if (($user->pending_referral ?? 0) < $amount) return false;

        // Perform payout by moving pending -> available and creating a Transaction record via creditToWallet
        return DB::transaction(function() use ($user, $amount) {
            $user->pending_referral = max(0, $user->pending_referral - $amount);
            $user->save();

            // credit to user's wallet as referral payout (uses Payable::creditToWallet)
            $tx = $user->creditToWallet($amount, [
                'type' => 'payout',
                'provider' => 'system',
                'method' => 'referral',
                'idempotency_key' => "referral_payout_user_{$user->id}_" . md5($amount . ':' . now()->timestamp),
                'label' => 'Referral payout',
            ]);

            // create referral event linking to the payout transaction
            ReferralEvent::create(['referral_id' => null, 'transaction_id' => $tx->id, 'type' => 'paid_out', 'meta' => ['user_id' => $user->id, 'amount' => $amount]]);

            return true;
        });
    }
}
