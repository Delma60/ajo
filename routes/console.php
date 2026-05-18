<?php

use App\Jobs\ProcessDueGroupCycles;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;


Schedule::call(function () {
     $now = Carbon::now();

        $dueGroups = Group::where('status', 'active')
            ->get();
            foreach ($dueGroups as $group) {
                $payoutDate = $group->next_payout;
                $timeFrame = $payoutDate->lessThanOrEqualTo($now);
                if($timeFrame){
                    Log::info("[Alert]: Checking groups cycles");
                    ProcessDueGroupCycles::dispatch($group->id)->onQueue("group-cycle");
                }
        }
})->everyTenMinutes();

Schedule::call(function () {
    Log::info("[Alert]: Daily Check to wallet");
    $users = User::all();
    $cutoff = Carbon::now()->subDay();

    $users->map(function($user) use($cutoff){
        $bals = $user->pendingBalances()->where("created_at", "<=", $cutoff)->get();
        // TODO:: Add Notification
        // TODO:: Add the user pending to $user->available_balance
        foreach ($bals as $bal) {
            $user->creditToWallet($bal->amount);
            file_put_contents("{$user->name}_balances.json", $bal);
        }

    });
})->daily();
