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
        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            // who initiated the invite/request (admin or applicant)
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            // who receives it (user invited OR admin that will respond to join request)
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['invite', 'request']); // invite = admin -> user, request = user -> admin/group
            $table->enum('status', ['pending','accepted','rejected','cancelled'])->default('pending');
            $table->string('role')->nullable();
            $table->text('message')->nullable();
            $table->string('token')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'recipient_id', 'sender_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
