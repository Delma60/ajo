<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');


Schedule::command("groups:process-cycles")
->everyTenMinutes()
->withoutOverlapping()
->onFailure(function(){
    Log::info("[Scheduler Error]: Failed to run schedule");
})
->runInBackground();


Schedule::call(function () {
    Log::info("[Alert]: Daily Check to wallet");
    $users = User::all();
    $cutoff = Carbon::now()->subDay();

    $users->map(function($user) use($cutoff){
        $bals = $user->pendingBalances()->where("created_at", "<=", $cutoff)->get();
        // TODO:: Add Notification
        // TODO:: Add the user pending to $user->available_balance
        foreach ($bals as $bal) {
            $user->creditToWallet($bal);
        }
        @file_put_contents("{$user->name}_balances.json", $bals);

    });
})->daily();
