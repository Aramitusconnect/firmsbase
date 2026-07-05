<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_message_links — the email-to-client/matter association table
 * (approved correction — replaces the removed email_threads concept
 * for linking purposes; a message may be linked more than once, e.g.
 * to a client AND a specific matter). No uuid — a join/association
 * row, not an independently-referenced workflow record (Phase 8
 * api_key_scopes precedent).
 *
 * At least one of matter_id/client_id must be set — enforced by
 * EmailMessageLinkingService, not a DB constraint (portability, same
 * reasoning as Phase 8's dual-actor "exactly one" checks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_message_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('linked_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['email_message_id']);
            $table->index(['firm_id', 'matter_id']);
            $table->index(['firm_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message_links');
    }
};
