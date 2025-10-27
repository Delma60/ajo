<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // Global references
            $table->uuid('uuid')->unique()->index();
            $table->string('reference')->nullable()->index(); // internal human ref
            $table->string('idempotency_key')->nullable()->index();

            // Relations
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('SET NULL');
            $table->foreignId('group_id')->nullable()->constrained()->onDelete('SET NULL');
            $table->foreignId('pending_account_balance_id')->nullable()->constrained('pending_account_balances')->onDelete('SET NULL');

            // Money values (use decimal, not float)
            $table->decimal('amount', 16, 2)->default(0);       // gross amount (positive)
            $table->decimal('fee', 16, 2)->default(0);          // fee charged
            $table->decimal('net_amount', 16, 2)->default(0);   // amount after fees (positive for credit to recipient)
            $table->string('currency', 8)->default('NGN');

            // Type & direction
            $table->string('type')->index();    // e.g. charge, payout, refund, topup, transfer
            $table->string('direction')->index(); // 'debit' | 'credit' relative to user wallet

            // Provider / method
            $table->string('provider')->nullable()->index(); // e.g. 'flutterwave'
            $table->string('method')->nullable();            // e.g. 'card', 'bank_account', 'wallet'

            // Provider reference & status
            $table->string('provider_reference')->nullable()->index();
            $table->enum('status', ['pending','processing','success','failed','cancelled'])->default('pending')->index();

            // control & metadata
            $table->unsignedInteger('attempts')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // Useful indexes for typical queries
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
