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
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // flutterwave, paystack
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('mode')->default('live'); // live | test
            $table->string('public_key')->nullable();
            $table->text('secret_key')->nullable();   // store encrypted
            $table->string('webhook_secret')->nullable();
            $table->json('meta')->nullable(); // fees, supported methods, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }

    
};
