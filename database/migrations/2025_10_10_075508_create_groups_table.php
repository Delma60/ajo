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
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->text("description")->nullable();
            $table->timestamp("nextDue");
            $table->double("goal");
            $table->double("saved")->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->onDelete('set null');

            $table->enum("frequency", ['weekly', 'monthly', 'bi-weekly', 'daily']);
            $table->enum("status", ['active', 'paused', 'closed'])->default("active");

            $table->json("meta")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
