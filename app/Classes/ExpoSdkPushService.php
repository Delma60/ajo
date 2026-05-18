<?php

namespace App\Classes;

use ExpoSDK\Expo;
use ExpoSDK\ExpoMessage;
use App\Models\ExpoPushToken;

class ExpoSdkPushService
{
    protected Expo $expo;
    protected int $chunkSize = 100; // Expo recommends <= 100 messages per request
    // protected string $receiptsUrl = 'https://exp.host/--/api/v2/push/getReceipts';

    public function __construct()
    {
        $this->expo = new Expo();
    }

    /**
     * Build ExpoMessage objects from a mixed array (array|ExpoMessage)
     *
     * @param array $messages
     * @return ExpoMessage[]
     */
    protected function normalizeMessages(array $messages): array
    {
        return array_map(function ($m) {
            if ($m instanceof ExpoMessage) return $m;
            return new ExpoMessage($m);
        }, $messages);
    }

    /**
     * Send messages, returns array with raw responses, mapping token=>ticketId and the list of ticket ids.
     *
     * @param array $messages (each message can have 'to' filled or not)
     * @param array $defaultRecipients tokens to use when a message has no 'to'
     * @return array
     */
    public function send(array $messages, array $defaultRecipients = []): array
    {
        $messages = $this->normalizeMessages($messages);

        // If some messages do not have 'to', we will assign default recipients by cloning the message for each recipient.
        $expanded = [];
        foreach ($messages as $msg) {
            $payload = $msg; // ExpoMessage instance
            $to = $payload->get('to') ?? null; // depending on SDK you may need ->getData() ; be defensive
            if (empty($to)) {
                if (empty($defaultRecipients)) {
                    // skip messages without recipient
                    continue;
                }
                foreach ($defaultRecipients as $recipient) {
                    // app/Classes/ExpoSdkPushService.php
                    $clone = new ExpoMessage(method_exists($payload, 'toArray') ? $payload->toArray() : (array)$payload); // defensive
                    $clone->setTo($recipient);
                    $expanded[] = $clone;
                }
            } else {
                $expanded[] = $payload;
            }
        }

        // Chunk and send
        $chunks = array_chunk($expanded, $this->chunkSize);
        $rawResponses = [];
        $tokenToTicket = []; // token => ticket id
        $ticketIds = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            // SDK supports (new Expo)->send($messagesArray)->to($defaultRecipients)->push()
            // but we already set 'to' on each message, so we can call send with chunk only.
            try {
                // convert chunk messages to SDK friendly format (array or ExpoMessage)
                $chunkForSdk = array_map(function (ExpoMessage $m) {
                    return $m; // SDK will accept ExpoMessage instance per your snippet
                }, $chunk);

                $result = $this->expo->send($chunkForSdk)->push();
            } catch (\Throwable $e) {
                // network or sdk exception, record failure
                $rawResponses[] = [
                    'ok' => false,
                    'exception' => $e->getMessage(),
                    'chunk_index' => $chunkIndex,
                    'count' => count($chunk),
                ];

                // mark mapping null
                foreach ($chunk as $m) {
                    try {
                        $to = method_exists($m, 'get') ? $m->get('to') : ($m->to ?? null);
                        if ($to) $tokenToTicket[$to] = null;
                    } catch (\Throwable $_) {
                        // ignore
                    }
                }
                continue;
            }

            // Normalise result shape. Typical SDK returns something like an array of ticket objects.
            $rawResponses[] = ['ok' => true, 'chunk_index' => $chunkIndex, 'response' => $result];

            // Try to extract tickets in various shapes
            // $result might be an array of tickets or ['data' => [...]] or object
            $tickets = [];
            if (is_array($result)) {
                // if numeric-indexed array and each has 'id' => it's tickets
                if (array_values($result) === $result && !empty($result) && is_array($result[0])) {
                    $tickets = $result;
                } elseif (isset($result['data']) && is_array($result['data'])) {
                    $tickets = $result['data'];
                } elseif (isset($result['tickets'])) {
                    $tickets = $result['tickets'];
                }
            }

            // Map chunk messages -> tickets 1:1 by index
            foreach ($chunk as $idx => $message) {
                $token = null;
                try {
                    // Try different ways to read 'to'
                    if (method_exists($message, 'get')) $token = $message->get('to');
                    elseif (isset($message->to)) $token = $message->to;
                    elseif (method_exists($message, 'getTo')) $token = $message->getTo();
                } catch (\Throwable $_) { $token = null; }

                $ticketId = null;
                if (isset($tickets[$idx])) {
                    if (is_array($tickets[$idx]) && isset($tickets[$idx]['id'])) {
                        $ticketId = $tickets[$idx]['id'];
                    } elseif (is_string($tickets[$idx])) {
                        $ticketId = $tickets[$idx];
                    } elseif (is_object($tickets[$idx]) && isset($tickets[$idx]->id)) {
                        $ticketId = $tickets[$idx]->id;
                    }
                }

                if ($token) {
                    $tokenToTicket[$token] = $ticketId;
                }
                if ($ticketId) $ticketIds[] = $ticketId;
            }
        }

        return [
            'raw' => $rawResponses,
            'token_to_ticket' => $tokenToTicket,
            'ticket_ids' => array_values(array_unique(array_filter($ticketIds))),
        ];
    }

    /**
     * Fetch receipts from Expo directly (fallback / or used if SDK lacks method)
     *
     * @param array $ticketIds
     * @return array
     */
    public function getReceipts(array $ticketIds): array
    {
        $ticketIds = array_values(array_unique(array_filter($ticketIds, fn($t) => is_string($t) && $t !== '')));
        if (empty($ticketIds)) return [];

        $res = $this->expo->getReceipts($ticketIds);
        $data = $res->getData();
        return ['ok' => true, 'body' => $data];
    }

    /**
     * Inspect receipts and remove DeviceNotRegistered tokens
     *
     * @param array $receiptResp result from getReceipts()
     * @param array $ticketToToken map ticketId => token
     * @return array report
     */
    public function handleReceipts(array $receiptResp, array $ticketToToken = []): array
    {
        $report = ['deleted_tokens' => [], 'errors' => [], 'processed' => []];
        $body = $receiptResp['body'] ?? $receiptResp;

        if (!is_array($body)) {
            $report['errors'][] = 'Unexpected receipts format';
            return $report;
        }

        foreach ($body as $receiptId => $info) {
            if (!is_array($info) && !is_object($info)) {
                $report['processed'][$receiptId] = ['raw' => $info];
                continue;
            }
            $status = is_array($info) ? ($info['status'] ?? null) : ($info->status ?? null);

            if ($status === 'ok') {
                $report['processed'][$receiptId] = ['status' => 'ok'];
                continue;
            }

            // status === 'error'
            $details = is_array($info) ? ($info['details'] ?? []) : ($info->details ?? []);
            $errorCode = is_array($details) ? ($details['error'] ?? null) : ($details->error ?? null);
            $message = is_array($info) ? ($info['message'] ?? null) : ($info->message ?? null);
            $report['errors'][$receiptId] = $message ?: $errorCode ?: 'unknown';

            if ($errorCode === 'DeviceNotRegistered') {
                $token = $ticketToToken[$receiptId] ?? null;
                if ($token) {
                    try {
                        ExpoPushToken::where('token', $token)->delete();
                        $report['deleted_tokens'][] = $token;
                    } catch (\Throwable $e) {
                        $report['errors'][$receiptId . '_delete_failed'] = $e->getMessage();
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Convenience: send broadcast to all tokens in DB (optionally filtered by user_id)
     */
    public function broadcast(string $title, string $body, array $data = [], ?int $userId = null): array
    {
        $query = ExpoPushToken::query();
        if ($userId !== null) $query->where('user_id', $userId);
        $tokens = $query->pluck('token')->toArray();

        $messages = array_map(function ($token) use ($title, $body, $data) {
            return new ExpoMessage([
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        }, $tokens);

        return $this->send($messages);
    }
}

