<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_webhook_verification_failures — Checkpoint 1 (FirmsVault
 * Live Integrations, checkpoint1-design-health-sandbox.md §A.3.3;
 * checkpoint1-security-review.md Finding 5). A durable counter of every
 * inbound webhook request InboundWebhookController rejects with the
 * collapsed `401 {"status":"rejected"}` response (plus the distinct
 * `malformed_payload` 400 branch) — feeds
 * `integration_platform_provider_health_summaries.webhook_verification_failure_count`
 * (see that table's own metrics-columns migration).
 *
 * WHY THIS TABLE HAS NO RLS (same exemption class as
 * `integration_webhook_receipts` — see that table's own "WHY THIS
 * TABLE HAS NO RLS" docblock for the full reasoning this mirrors):
 *   - A rejected inbound signature frequently cannot be attributed to
 *     a resolved firm_integration_id at all (an attacker can send a
 *     garbage routing token that never maps to any connection) —
 *     structurally incapable of carrying a tenant-identifying column,
 *     and none may ever be added.
 *   - Platform-owned, pre-tenant, write-only from the caller's
 *     perspective (App\Integrations\Jobs\RecordWebhookVerificationFailureJob
 *     is the sole writer — a queued job, deliberately NOT a
 *     synchronous write on InboundWebhookController's own
 *     timing-critical request path, per checkpoint1-security-review.md
 *     Finding 5).
 *   - Every column is a sanitized closed-vocabulary label +
 *     timestamp — never a raw header, token, signature, or request
 *     body fragment.
 *
 * `failure_reason` mirrors the exact closed rejection-reason
 * vocabulary InboundWebhookController already branches on internally
 * (see that controller's own rejectEarly()/dispatchVerificationFailure()
 * call sites) — a plain string column (matching this codebase's
 * established "no DB enum type" convention, e.g.
 * integration_webhook_receipts.verification_outcome), closed by an
 * explicit CHECK constraint rather than left open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_webhook_verification_failures', function (Blueprint $table) {
            $table->id();

            $table->string('provider_code');
            $table->string('failure_reason');
            $table->timestamp('occurred_at')->useCurrent();

            $table->timestamps();

            $table->index(['provider_code', 'occurred_at']);
            $table->index('occurred_at');

            // Deliberately NO enableRowLevelSecurity() call and NO
            // companion RLS migration for this table — see this
            // migration's class docblock ("WHY THIS TABLE HAS NO RLS")
            // for the full, required-reading reasoning.
        });

        DB::statement(<<<'SQL'
            ALTER TABLE integration_webhook_verification_failures ADD CONSTRAINT integration_webhook_verification_failures_reason_check CHECK (
                failure_reason IN ('signature_mismatch', 'missing_headers', 'malformed_payload', 'unknown_routing_token', 'disconnected_event_rejected')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_verification_failures');
    }
};
