<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Auth;

class ReferralController extends Controller
{
    protected $service;

    public function __construct(ReferralService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        // gather referred users and referral events summary
        $referrals = \App\Models\Referral::where('referrer_id', $user->id)->with('referred')->get();

        $referredUsers = $referrals->map(function($r) {
            return [
                'id' => $r->referred?->id,
                'name' => $r->referred?->name,
                'email' => $r->referred?->email,
                'accepted_at' => $r->accepted_at,
                'code' => $r->code,
            ];
        })->filter(fn($x) => !is_null($x['id']))->values();

        $invitedCount = $referredUsers->count();

        return response()->json([
            'referral_code' => $user->referral_code,
            'available_referral' => $user->available_referral,
            'pending_referral' => $user->pending_referral,
            'invited_count' => $invitedCount,
            'referred_users' => $referredUsers,
            'credited_at' => $user->referral?->credited_at ?? null,
        ]);
    }

    public function generate(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $code = $this->service->generateCode($user);
        // also update user's referral_code
        $user->referral_code = $code;
        $user->save();

        return response()->json(['referral_code' => $code]);
    }

    public function payout(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $amount = (float) $request->input('amount', 0);
        if ($amount <= 0) return response()->json(['message' => 'Invalid amount'], 422);

        $ok = $this->service->payoutReferrer($user, $amount);
        if (!$ok) return response()->json(['message' => 'Insufficient pending balance'], 422);

        return response()->json(['message' => 'Payout processed']);
    }
}
