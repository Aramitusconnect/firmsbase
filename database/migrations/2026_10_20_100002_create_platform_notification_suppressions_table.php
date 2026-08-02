<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_notification_suppressions — SES event consumer remediation
 * (post-578ee98 audit, finding H1). The platform-scope analogue of
 * SuppressionService's firm-scoped notification_events rows —
 * deliberately NOT SuppressionService/notification_events, which is
 * FORCE-RLS-protected and requires a resolved firm the entire
 * platform-notification-correlation path exists because one could NOT
 * be resolved.
 *
 * One row per suppressed recipient_fingerprint (unique) — a current-
 * state table, not an append-only event log like notification_events.
 * PlatformNotificationCorrelationService::recordOutcome() always
 * upserts (updateOrCreate on recipient_fingerprint), making repeated/
 * duplicate suppression attempts for the same address a safe no-op —
 * this is the platform-scope path's own idempotency guard, independent
 * of the ses_event_receipts ledger, matching the audit's "defense in
 * depth" requirement.
 *
 * Read by User::sendPasswordResetNotification()/
 * ClientPortalUser::sendPasswordResetNotification() BEFORE attempting a
 * real send on the uncorrelated-firm fallback path, to satisfy "prevent
 * repeated sending to permanently bounced addresses" — a real SES send
 * is skipped entirely (never attempted) when a fingerprint match is
 * found here.
 *
 * No firm_id, no RLS: this table has no tenant at all by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_suppressions', function (Blueprint $table) {
            $table->id();

            $table->string('recipient_fingerprint')->unique();
            $table->string('status');
            $table->uuid('correlation_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('suppressed_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_suppressions');
    }
};
