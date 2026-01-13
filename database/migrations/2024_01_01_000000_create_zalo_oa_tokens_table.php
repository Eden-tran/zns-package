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
        Schema::create('zalo_oa_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('oa_id')->unique();
            $table->string('app_id');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->integer('expires_in')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zalo_oa_tokens');
    }
};