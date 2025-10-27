<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\ReferralService;

class TransactionObserver
{
    protected $referralService;

    public function __construct(ReferralService $referralService)
    {
        $this->referralService = $referralService;
    }

    public function updated(Transaction $transaction)
    {
        // when a transaction becomes success, record contribution
        if ($transaction->isDirty('status') && $transaction->status === Transaction::STATUS_SUCCESS) {
            $this->referralService->recordContribution($transaction);
        }
    }
}
