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
        Schema::table('group_user', function (Blueprint $table) {
            //
            $table->decimal('outstanding_debit', 14, 2)->default(0);

            // number of missed cycles accumulated (optional/useful)
            $table->integer('cycles_missed')->default(0);

            // marker so we don't apply the same fee multiple times for the same payout period
            $table->timestamp('fee_assessed_at')->nullable()->comment('when a fee was last assessed for the current payout period');
        
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_user', function (Blueprint $table) {
            //
        });
    }
};
