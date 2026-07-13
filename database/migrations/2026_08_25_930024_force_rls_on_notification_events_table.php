<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 24 — permanently activates FORCE ROW LEVEL
 * SECURITY for notification_events.
 *
 * notification_events is tenant-owned (firm_id NOT NULL, carried
 * directly on the row), append-only (NotificationEvent::UPDATED_AT =
 * null), RLS-enabled with a standard existing policy created by this
 * repo's Phase 4 preparation migration (2026_07_07_800016_extend_row_
 * level_security_to_phase_4_tenant_tables.php) — notification_events_
 * tenant_isolation, USING firm_id = NULLIF(current_setting('app.
 * current_firm_id', true), '')::bigint, no separate WITH CHECK —
 * unchanged by this migration. notification_template_id, client_id,
 * and matter_id are all nullable foreign keys. No unrelated table's
 * schema needed to change.
 *
 * Verified writer inventory (grep -rn "NotificationDispatchService|
 * SuppressionService" app/Services/ app/Jobs/ app/Http/ app/Listeners/
 * app/Console/, excluding the defining files themselves and mapping/
 * governance-service docblock-only mentions):
 *
 *   - NotificationDispatchService::dispatch() has ZERO production
 *     callers anywhere in app/ — confirmed. Its only "callers" in a
 *     grep for the class name are its own definition file,
 *     DispatchNotificationJob (which is dispatched FROM INSIDE
 *     dispatch() itself, never from any external caller), and a
 *     handful of read-only docblock/mapping-service references
 *     (NotificationTemplate, NotificationEvent, SenderDomainStatus,
 *     EmailSyncService, EmailDeliverabilityNonBypassGuard,
 *     DeadlineService, NotificationDispatchResult — none of these
 *     actually invoke dispatch()).
 *   - recordFailed() (public) has ZERO callers anywhere, including
 *     inside dispatch() itself (dispatch() only ever calls the private
 *     recordEvent() and, once, recordSent() is called by the QUEUED
 *     job rather than by dispatch() directly).
 *   - SuppressionService::recordBounce() and ::recordComplaint() (both
 *     public) have ZERO callers anywhere.
 *   - SuppressionService::isSuppressed() (a READ, not a writer) DOES
 *     have one live call chain: NotificationEligibilityService::check()
 *     -> DocumentChaseService::checkAndLog(). checkAndLog() already
 *     wraps its ENTIRE body (including this transitive
 *     notification_events read) in one runWithFirmContext() call,
 *     established at Section 39A-3L Checkpoint 10 when document_chase_*
 *     was forced. No change needed for this read path.
 *
 * Conclusion: the entire notification_events WRITE pathway (dispatch(),
 * the private recordEvent() it calls, recordSent() as invoked by the
 * queued DispatchNotificationJob, recordFailed(), recordBounce(), and
 * recordComplaint()) is dormant/unwired in production today — confirmed
 * directly, matching the pre-investigation finding. This does not change
 * the required wiring: every one of these methods already receives a
 * trusted Firm $firm parameter directly (no circular-dependency issue),
 * so per this mission's established precedent (Checkpoint 22's
 * PaymentPlanDunningService/ProductionPilotWorkflowService::
 * createAndActivatePaymentPlan() treatment of dormant-but-present
 * methods), each is wired now rather than left as a landmine for
 * whoever wires a live caller to it next.
 *
 * Required production fixes:
 *
 *   - NotificationDispatchService::dispatch(): wraps its ENTIRE body in
 *     one runWithFirmContext($firm, ...) call, matching this mission's
 *     established default pattern (see PaymentPlanService::create()).
 *     dispatch() receives $firm directly, and none of its three
 *     collaborators — NotificationTemplateService::resolve(),
 *     SenderDomainVerificationService::isVerified(), and
 *     NotificationEligibilityService::check() (which reads FORCE-RLS-
 *     protected communication_consents/client_communication_preferences,
 *     forced since Checkpoint 11/39A-3K) — self-wrap. An earlier version
 *     of this fix instead gave each of the four internal recordEvent()
 *     call sites its own independent tight wrap; that was wrong and was
 *     caught by the test-verifier before review: the first such wrap's
 *     own finally cleared ambient context before eligibility->check()
 *     could run, making dispatch() permanently unable to reach
 *     accepted=true. The whole-method wrap below is the corrected,
 *     final design — the four recordEvent() calls execute as plain
 *     sequential calls relying on the one active outer context, never
 *     their own nested wrap.
 *   - NotificationDispatchService::recordSent() and ::recordFailed():
 *     each now wraps its own single NotificationEvent::create() call in
 *     its own tight runWithFirmContext($firm, ...) call. recordSent()
 *     is called by DispatchNotificationJob::handle() (a queued job,
 *     which runs with no ambient context of its own); wrapping the
 *     callee here is sufficient and is the minimal fix — the job itself
 *     needed no separate wrap added, since recordSent() is the job's
 *     only notification_events touch point and it is now self-
 *     contained.
 *   - SuppressionService::recordBounce() and ::recordComplaint(): each
 *     now wraps its own single NotificationEvent::create() call in its
 *     own tight runWithFirmContext($firm, ...) call, identical pattern.
 *
 * Required factory fix — NotificationEventFactory: notification_
 * template_id, client_id, and matter_id are all nullable and already
 * default to null in definition(), so the bare/default path cannot
 * produce a cross-firm mismatch (confirmed directly — no existing state
 * helper, e.g. blocked(), sets any of these three relations at all).
 * No "one authoritative firm" fix was needed. Added the standard
 * context-hold create() override anyway (identical pattern to
 * PaymentPlanEventFactory/PaymentPlanFactory/InvoiceFactory/
 * TimeEntryFactory), matching this mission's universal safety-net
 * convention for every FORCE-RLS factory regardless of whether a bare-
 * call cross-firm bug exists today.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - No validation that a notification_events row's
 *     notification_template_id/client_id/matter_id actually belong to
 *     the same firm as its own firm_id column — the same accepted "RLS
 *     only checks this row's own firm_id" boundary as every prior
 *     checkpoint. Every production writer above always derives firm_id
 *     directly from an explicitly-passed Firm, so this gap has no known
 *     live trigger today; it is a database-layer constraint gap, not a
 *     demonstrated bug.
 *   - The entire write pathway remains dormant in production (no live
 *     caller of dispatch()/recordFailed()/recordBounce()/
 *     recordComplaint() exists yet); the wiring added here activates
 *     correctly the moment a real caller is wired in, but has no
 *     observable effect today.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'notification_events';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' NO FORCE ROW LEVEL SECURITY');
    }

    private function quoteIdentifier(string $table): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new \RuntimeException("Refusing to activate FORCE RLS on an unsafe/unexpected identifier: {$table}");
        }

        return '"'.$table.'"';
    }
};
