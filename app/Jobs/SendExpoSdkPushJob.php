<?php

namespace App\Jobs;

use App\Classes\ExpoSdkPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendExpoSdkPushJob implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $messages;
    public array $defaultRecipients;

    public function __construct(array $messages, array $defaultRecipients = [])
    {
        $this->messages = $messages;
        $this->defaultRecipients = $defaultRecipients;
    }

    public function handle(ExpoSdkPushService $service)
    {
        $result = $service->send($this->messages, $this->defaultRecipients);

        $ticketIds = $result['ticket_ids'] ?? [];
        $tokenToTicket = $result['token_to_ticket'] ?? [];

        // invert mapping ticket => token for receipts handling
        $ticketToToken = [];
        foreach ($tokenToTicket as $token => $ticket) {
            if ($ticket) $ticketToToken[$ticket] = $token;
        }

        if (!empty($ticketIds)) {
            // schedule receipt processing after a short delay
            ProcessExpoSdkReceiptsJob::dispatch($ticketIds, $ticketToToken)->delay(now()->addSeconds(30));
        }

        // Optionally log or store $result for debugging
        Log::info('SendExpoSdkPushJob result', $result);
    }

}
