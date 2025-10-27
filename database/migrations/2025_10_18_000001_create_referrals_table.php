<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('channel')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_events', function (Blueprint $table) {
            $table->id();
            // allow null referral_id for generic paid_out events that may not map to a single referral
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('type'); // created, signup, contribution, credited, paid_out
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_events');
        Schema::dropIfExists('referrals');
    }
};
