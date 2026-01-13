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
        Schema::create('zns_sending_logs', function (Blueprint $table) {
            $table->id();
            $table->string('oa_id')->index()->comment('Zalo Official Account ID');
            $table->string('phone')->index()->comment('Recipient phone number');
            $table->string('template_id')->index()->comment('Template ID used');
            $table->json('template_data')->nullable()->comment('Template data sent');
            $table->string('tracking_id')->unique()->nullable()->comment('Unique tracking ID');
            $table->string('msg_id')->nullable()->index()->comment('Message ID from Zalo response');
            $table->string('mode')->nullable()->comment('Sending mode: development/production');
            $table->tinyInteger('status')->default(0)->index()->comment('0:pending, 1:sent, 2:delivered, 3:failed');
            $table->text('error_message')->nullable()->comment('Error message if failed');
            $table->integer('error_code')->nullable()->comment('Error code from API');
            $table->json('zns_request')->nullable()->comment('Full request payload sent to Zalo');
            $table->json('zns_response')->nullable()->comment('Full response from Zalo API');
            $table->timestamp('sent_at')->nullable()->comment('Time message was sent');
            $table->timestamp('delivered_at')->nullable()->comment('Time message was delivered');
            $table->unsignedBigInteger('related_id')->nullable()->index()->comment('Related entity ID (contract, interest_rate, etc)');
            $table->string('related_type')->nullable()->comment('Related entity type (polymorphic)');
            $table->timestamps();

            // Composite index for polymorphic relation
            $table->index(['related_type', 'related_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zns_sending_logs');
    }
};
