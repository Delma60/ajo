<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_cards', function (Blueprint $table) {
            $table->id();
            // $table->foreignId("user_id")->constrained()->cascadeOnDelete();
             $table->string('provider_payment_method_id')->nullable()->unique();

            // Card info (non-sensitive)
            $table->string('brand')->nullable();       // e.g. "visa", "mastercard"
            $table->string('bank_name')->nullable();   // optional: from provider response
            $table->string('country')->nullable();     // e.g. "NG"
            $table->string('currency')->nullable();    // e.g. "NGN"
            $table->string('last4', 4)->nullable();    // last 4 digits only
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();

            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active');

            // Meta info (for storing gateway raw response if needed)
            $table->json('meta')->nullable();

            // Whether this is the default card for user
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_cards');
    }
};
