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
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
 
            // Severity: info | warning | critical | success
            $table->string('type', 20)->default('info')->index();
 
            // Domain area: payment | user | group | system | security
            $table->string('category', 30)->default('system')->index();
 
            $table->string('title');
            $table->text('body');
            $table->json('meta')->nullable();
 
            $table->boolean('is_read')->default(false)->index();
 
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
 
            $table->timestamps();
 
            // Composite index for the most common admin query
            $table->index(['resolved_at', 'type', 'created_at']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
