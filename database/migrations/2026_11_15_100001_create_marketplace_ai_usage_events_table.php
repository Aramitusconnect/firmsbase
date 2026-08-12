<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketplace_ai_usage_events — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 6. Genuinely separate from the existing,
 * mature `ai_usage_events` (Firm + User, both DB-level NOT NULL
 * foreign keys) rather than weakening that table's constraints or
 * inventing a fake Firm/User/sentinel-tenant to satisfy them.
 *
 * A MyAttorney visitor's AI usage falls into two real cases neither
 * of which has a `User` row to attribute to (the visitor becomes a
 * real User only after conversion, checkpoint 11):
 *   1. PRE-FIRM classification (MarketplaceIssueClassifierService) —
 *      no Firm exists yet either. firm_id is NULL.
 *   2. FIRM-SCOPED conversational intake, after a Firm has been
 *      selected (MarketplaceIntakeConversationalAssistantService) —
 *      a real Firm exists (its own AiModeResolutionService/
 *      AiBudgetEnforcementService gates still apply, both of which
 *      are Firm-only, no User needed — confirmed in checkpoint 5's
 *      own research), but the actor is still an anonymous prospect.
 *      firm_id is set.
 *
 * firm_id nullable, FORCE RLS policy shape copied byte-for-byte from
 * security_events' own Phase B6 design (see that table's own
 * preparation migration): a firm-scoped session may read/write only
 * its own firm's rows, plus the platform-wide (firm_id IS NULL) rows
 * are visible/writable ONLY when no tenant context is active at all
 * — never leaking one firm's rows to another, and never leaking
 * platform-wide rows into an active firm's own read path by accident
 * (matching MarketplaceCorrectionService's own runWithoutFirmContext()
 * convention for genuinely anonymous audit rows).
 *
 * session_hash is a sha1 of the visitor's own Laravel session id
 * (never the raw session id itself, matching
 * AccountLoginThrottleService's own "hashed composite key" convention)
 * — the key MarketplaceAiUsageThrottleService/rolling-window ceiling
 * checks group by. marketplace_intake_id is nullable: null for
 * pre-Firm classification calls (no MarketplaceIntake exists yet at
 * that point), set once a Firm-scoped intake session exists.
 *
 * Deliberately does NOT carry raw prompt/response text — only the
 * same coarse accounting fields ai_usage_events itself carries
 * (provider/model/action_type/tokens_in/tokens_out) plus session/IP
 * for abuse-ceiling purposes, matching this mission's own
 * sensitive-logging prohibitions (never log raw narrative, AI raw
 * prompt/response).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_ai_usage_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();
            $table->foreignId('marketplace_intake_id')->nullable()->constrained('marketplace_intakes')->nullOnDelete();

            $table->string('session_hash');
            $table->string('ip_address', 45)->nullable();

            $table->string('provider');
            $table->string('model');
            $table->string('action_type');
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_id');
            $table->index('marketplace_intake_id');
            $table->index(['session_hash', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_ai_usage_events');
    }
};
