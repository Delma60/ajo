<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('referrals')) return;
        Schema::table('referrals', function (Blueprint $table) {
            if (!Schema::hasColumn('referrals', 'credited_at')) {
                $table->timestamp('credited_at')->nullable()->after('accepted_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('referrals')) return;
        Schema::table('referrals', function (Blueprint $table) {
            if (Schema::hasColumn('referrals', 'credited_at')) {
                $table->dropColumn('credited_at');
            }
        });
    }
};
