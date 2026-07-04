<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * communication_consents — the compliance record: consent must be
 * captured, versioned, and enforced before automated SMS, WhatsApp,
 * email, or portal notifications. Gets a uuid because it is a
 * legal/compliance artifact. client_id is a deferred FK, same as
 * client_communication_preferences. consent_text_version records the
 * exact version of consent language agreed to, so a later copy change
 * never silently reinterprets a past consent event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();

            $table->string('channel');
            $table->string('status')->default('unknown');
            $table->string('consent_text_version');

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('captured_via')->nullable();
            $table->string('captured_ip', 45)->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('client_id');
            $table->unique(['firm_id', 'client_id', 'channel'], 'comm_consents_firm_client_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_consents');
    }
};
