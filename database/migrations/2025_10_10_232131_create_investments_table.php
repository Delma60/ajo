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
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->string("title");
            $table->string("subtitle");
            $table->text("description");
            $table->double("min_investment");
            $table->enum("status", ['active', 'paused', 'closed', 'draft']);
            $table->enum("risk", ['low', 'medium', 'high']);
            $table->double("raised")->default(0);
            $table->double("target");
            $table->float("apy");
            $table->float("duration");
            $table->timestamp("end_date")->nullable();
            $table->timestamp("start_date")->nullable();
            $table->json("meta")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
