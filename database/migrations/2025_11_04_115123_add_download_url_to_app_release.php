<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
     public function up()
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->string('download_url')->nullable()->after('file_path');
            $table->unsignedBigInteger('download_count')->default(0)->after('download_url');
        });
    }

    public function down()
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->dropColumn(['download_url', 'download_count']);
        });
    }
};
