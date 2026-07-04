<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * clients — the record created by lead conversion (never created any
 * other way — see LeadConversionService). communication_preferences_id
 * links to Phase 1's client_communication_preferences (already exists);
 * that table's own client_id gets its real FK completed in a later
 * migration in this same batch, now that this table finally exists.
 *
 * portal_status/portal_invitation_* prepare the schema for basic
 * client-portal access; no invitation email/SMS is actually sent in
 * Phase 2 (gated on Phase 4 deliverability + Phase 1 consent
 * enforcement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('communication_preferences_id')
                ->nullable()
                ->constrained('client_communication_preferences')
                ->nullOnDelete();

            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('preferred_language', 10)->default('en');
            $table->string('preferred_timezone')->nullable();

            $table->string('portal_status')->default('not_invited');
            $table->string('portal_invitation_token')->nullable()->unique();
            $table->timestamp('portal_invitation_sent_at')->nullable();
            $table->timestamp('portal_invitation_accepted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
