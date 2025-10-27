<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDueGroupCycles as JobsProcessDueGroupCycles;
use App\Models\Group;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDueGroupCycles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'groups:process-cycles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find groups whose cycle is due and dispatch processing jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $now = Carbon::now();

        $dueGroups = Group::where('status', 'active')
            ->get();
            foreach ($dueGroups as $group) {
                $payoutDate = Carbon::parse($group->next_payout);
                $timeFrame = $payoutDate->lessThanOrEqualTo($now);
                if($timeFrame){
                    JobsProcessDueGroupCycles::dispatch($group->id)->onQueue("group-cycle");
                }
        }

        return 0;
    }
}
