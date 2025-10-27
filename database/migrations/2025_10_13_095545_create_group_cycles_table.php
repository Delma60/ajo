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
        Schema::create('group_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId("group_id")->constrained()->cascadeOnDelete();
            $table->double("cycle_number");
            $table->string("recipient");
            $table->timestamp("start_at")->nullable();
            $table->timestamp("end_at")->nullable();
            $table->double("amount");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_cycles');
    }
};
