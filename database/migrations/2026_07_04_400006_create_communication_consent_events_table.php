<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * communication_consent_events — append-only audit trail. No uuid, no
 * updated_at, created_at only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_consent_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('communication_consent_id')->constrained('communication_consents')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('action');
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->string('consent_text_version');

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->nullable();
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('communication_consent_id');
            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_consent_events');
    }
};
