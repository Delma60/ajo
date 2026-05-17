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
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16)->default('android'); // android | ios
            $table->string('version', 32); // semantic e.g. 1.2.3
            $table->unsignedBigInteger('build')->default(0); // numeric build code for easy compare
            $table->string('file_path'); // storage path or S3 key
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 128)->nullable();
            $table->boolean('is_published')->default(false); // visible to clients
            $table->boolean('is_supported')->default(true); // whether backend considers this release supported
            $table->boolean('is_forced_update')->default(false); // force update vs optional
            $table->text('release_notes')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['platform','build']);
            $table->unique(['platform','build']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
