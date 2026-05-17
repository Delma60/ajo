<?php

namespace App\Jobs;

use App\Classes\ExpoSdkPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessExpoSdkReceiptsJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $ticketIds;
    public array $ticketToToken;

    public function __construct(array $ticketIds, array $ticketToToken = [])
    {
        $this->ticketIds = $ticketIds;
        $this->ticketToToken = $ticketToToken;
    }

    public function handle(ExpoSdkPushService $service)
    {
        $resp = $service->getReceipts($this->ticketIds);
        $report = $service->handleReceipts($resp, $this->ticketToToken);

        Log::info('Expo receipts processed', $report);
    }
}
