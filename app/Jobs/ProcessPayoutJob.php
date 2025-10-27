<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPayoutJob implements ShouldQueue
{
    use Queueable;

    public $groupId;
    public $userIds;

    /**
     * Create a new job instance.
     */
    public function __construct($gId, array $userIds)
    {
        //
        $this->groupId = $gId ;
        $this->userIds = $userIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
    }
}
