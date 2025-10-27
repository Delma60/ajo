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
        // database/migrations/xxxx_xx_xx_create_group_user_table.php
        Schema::create('group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // extra pivot data
            $table->string('role')->default('member'); // e.g. 'member', 'admin', 'treasurer'
            $table->timestamp('joined_at')->nullable();
            $table->double('contributed')->default(0);
            $table->double('received')->default(0);
            
            // $table->double('received')->default(0);

            $table->timestamps();

            // avoid duplicates
            $table->unique(['group_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_user');
    }
};
