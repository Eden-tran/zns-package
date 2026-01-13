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
        Schema::create('zns_templates', function (Blueprint $table) {
            $table->id();
            $table->string('oa_id')->index()->comment('Zalo Official Account ID');
            $table->string('template_id')->unique()->comment('Zalo Template ID');
            $table->string('template_name')->comment('Template display name');
            $table->text('template_content')->nullable()->comment('Message template content');
            $table->tinyInteger('status')->default(1)->index()->comment('1:active, 2:pending, 3:rejected, 4:inactive, 5:deleted');
            $table->string('status_raw')->nullable()->comment('Original Zalo status string');
            $table->string('preview_url')->nullable()->comment('Template preview URL');
            $table->string('template_tag')->nullable()->comment('Template category/tag');
            $table->string('template_quality')->nullable()->comment('Template quality rating');
            $table->json('template_params')->nullable()->comment('Array of parameter definitions with validation rules');
            $table->integer('timeout')->nullable()->comment('Message timeout in seconds');
            $table->boolean('apply_template_quota')->default(false)->comment('Whether template has per-template quota');
            $table->integer('template_daily_quota')->nullable()->comment('Daily quota for this template');
            $table->integer('template_remaining_quota')->nullable()->comment('Remaining quota today');
            $table->decimal('price', 10, 2)->nullable()->comment('Cost per message in VND');
            $table->timestamp('zalo_created_time')->nullable()->comment('Created time from Zalo');
            $table->timestamp('zalo_updated_time')->nullable()->comment('Updated time from Zalo');
            $table->timestamp('last_synced_at')->nullable()->comment('Last sync from Zalo API');
            $table->boolean('is_active')->default(true)->index()->comment('Local enable/disable flag');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zns_templates');
    }
};
