<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketplace_intake_events — Mission 3, checkpoint 1. Append-only
 * audit trail for a marketplace_intakes row's lifecycle, mirroring
 * payment_request_events exactly (see that table's own migration for
 * the full rationale). actor_firm_user_id is nullable — a public
 * visitor progressing their own intake is never a FirmUser; it is
 * populated only for Firm-side events (under review, accepted,
 * declined).
 *
 * metadata carries ONLY coarse, structured facts (event-specific
 * small keys — e.g. {"status_from":"started","status_to":"submitted"})
 * — never raw prospect narrative, never document contents, never AI
 * prompt/response text, matching the Mission 3 spec's sensitive-
 * logging prohibitions verbatim. Callers, not this table, are
 * responsible for that boundary — enforced by code review and the
 * adversarial test matrix (checkpoint 15), not a database constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_intake_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('marketplace_intake_id')->constrained('marketplace_intakes')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'marketplace_intake_id']);
            $table->index(['firm_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_intake_events');
    }
};
