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
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event')->nullable();
            $table->string('status')->default('pending'); // delivered|failed|pending
            $table->integer('http_status')->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->text('payload');
            $table->string('reference')->nullable();
            $table->integer('retries')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
