<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for timeline_events, the seventh of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-timeline_events-design-dossier.md (APPROVED
 * by both rls-policy-designer and tenant-context-auditor, the latter
 * independently reproducing this dossier's central empirical claim a
 * third time, more adversarially than either prior reproduction, with
 * zero residual doubt reported).
 *
 * ---------------------------------------------------------------------
 * (a) Why firm_id is nullable here — an orphaned-audit-trail artifact,
 *     NOT a legitimate platform-wide-row pattern.
 * ---------------------------------------------------------------------
 * Every one of the six prior nullable-firm_id checkpoints in this arc
 * (backup_restore_tests, health_checks, incident_events,
 * maintenance_windows, notification_templates, pilot_feedback_items)
 * shared a common shape: firm_id = NULL meant a genuine, intentional,
 * platform-wide row (infrastructure monitoring, a shared template
 * default, internal-source feedback) that every tenant may legitimately
 * see. timeline_events does NOT share this shape. Its firm_id column is
 * declared nullable()->constrained('firms')->nullOnDelete()
 * (database/migrations/2026_07_05_600021_create_timeline_events_table.php:12)
 * — nullable purely so that IF a Firm row is ever deleted, its
 * historical timeline rows survive as an orphaned audit trail rather
 * than being cascade-deleted, not because any tenant is meant to see
 * them. TimelineEventRecorder::record(Firm $firm, ...)
 * (app/Services/TimelineEventRecorder.php:19) takes a REQUIRED,
 * non-nullable Firm parameter — there is no application code path that
 * has ever legitimately written a firm_id = NULL row. The only
 * mechanism that can ever produce one is the database's own
 * ON DELETE SET NULL foreign-key action, and no service anywhere in the
 * codebase currently deletes a Firm (OffboardingRequestService.php:17:
 * "Never deletes or destroys anything itself"). Blindly transplanting
 * the six prior tables' "firm_id IS NULL OR firm_id = current_firm,
 * visible to everyone" read policy onto this table would have been a
 * genuine NEW data-leak vector, not a safe default: it would make a
 * deleted firm's entire historical billing, payment, matter-opening,
 * and key-destruction activity narrative visible to every other tenant
 * on the platform the moment that firm is ever deleted.
 *
 * ---------------------------------------------------------------------
 * (b) Empirically verified: FK-cascade referential actions bypass RLS
 *     policy evaluation entirely, regardless of policy shape —
 *     independently reproduced three times.
 * ---------------------------------------------------------------------
 * Before designing this table's policy, the dossier verified directly
 * against a live, disposable Postgres instance (rls_test_runner_39a3l
 * role, confirmed non-superuser, rolbypassrls=false, matching the
 * application's own role characteristics), against a minimal isolated
 * parent/child schema with FORCE ROW LEVEL SECURITY active, that an
 * ON DELETE SET NULL cascade succeeds — with zero RLS violation — even
 * under a STRICT policy with no null-permitting branch at all (the
 * exact single-clause shape timeline_events' current, un-forced policy
 * already has). This was independently reproduced a SECOND time by
 * Design Reviewer 1 (rls-policy-designer), who built their own scratch
 * disposable database and re-ran the experiment from scratch rather
 * than trusting the dossier's own reported result — confirming the
 * cascade succeeds with zero RLS violation, and additionally confirming
 * the resulting orphaned rows are invisible to every context (including
 * no-context) and cannot be re-adopted by a firm-scoped UPDATE. It was
 * independently reproduced a THIRD time by Design Reviewer 2
 * (tenant-context-auditor), more adversarially than either prior
 * reproduction — using a MISMATCHED, not merely absent, ambient
 * context; confirming the row was completely invisible to that session
 * BEFORE the cascade; confirming the cascade still succeeded and
 * genuinely nulled the FK, not merely hid it — who reported ZERO
 * RESIDUAL DOUBT. Conclusion, empirically confirmed three independent
 * times, not inferred: PostgreSQL's foreign-key referential-action
 * triggers (ON DELETE SET NULL/CASCADE) bypass row-security policies on
 * the referencing table entirely, regardless of policy shape, even
 * under FORCE ROW LEVEL SECURITY with a non-bypass table owner.
 *
 * ---------------------------------------------------------------------
 * (c) Why this means the existing policy needs NO change at all.
 * ---------------------------------------------------------------------
 * Given (a) no application code ever legitimately writes firm_id =
 * NULL, and (b) the one mechanism that does produce a null row (the FK
 * cascade) is entirely unaffected by RLS policy shape — the correct
 * design is the existing, already-live policy, completely unchanged:
 *
 *   timeline_events_tenant_isolation (from database/migrations/
 *   2026_07_05_600024_extend_row_level_security_to_phase_2_tenant_tables.php):
 *     USING (firm_id = NULLIF(current_setting('app.current_firm_id',
 *     true), '')::bigint)
 *
 *   - Read: no IS NULL branch — orphaned rows become permanently
 *     invisible to every live tenant session once their owning firm is
 *     deleted. This is a deliberate, proven, fail-closed design
 *     decision, not an oversight or a gap. The row is retained (for
 *     whatever future compliance/legal-hold/audit purpose
 *     nullOnDelete() was chosen over cascadeOnDelete() for in the first
 *     place) but exposed to nobody.
 *   - Write: identical single clause doubles as WITH CHECK. No
 *     asymmetric branch is needed or wanted — nothing legitimate ever
 *     needs to write a firm_id = NULL row from application code, and
 *     adding a permissive branch for it (mirroring the six prior
 *     tables' pattern) would be a gratuitous widening serving no
 *     purpose, since the one case that does need to produce a null row
 *     (the FK cascade) doesn't consult this clause at all.
 *   - No two-policy FOR SELECT/FOR ALL split is needed. The DELETE-side
 *     gap that motivated the split for the six prior tables was
 *     specifically about a wide-open firm_id IS NULL USING clause
 *     interacting badly with WITH CHECK never being consulted for
 *     DELETE. Here, USING is never wide — it only ever matches the
 *     caller's own firm, for SELECT, UPDATE, and DELETE alike,
 *     symmetric with WITH CHECK by construction.
 *
 * This means timeline_events' existing, already-live policy text is
 * already exactly correct — no DROP POLICY/CREATE POLICY is issued by
 * this migration at all, mirroring the precedent already established
 * for firm_activation_events and notification_events (tables where
 * independent audits confirmed the existing single-clause policy was
 * already correct in shape, and the migration was a bare FORCE ROW
 * LEVEL SECURITY flip with no policy replacement). This checkpoint's
 * migration is the smallest of the eight in this nullable-firm_id
 * category — but its application-code prerequisite (below) is the
 * largest, comparable to or exceeding Phase B5's own scope.
 *
 * ---------------------------------------------------------------------
 * (d) Application-code prerequisite — 8 call-site fixes across 6
 *     services, already committed in a separate preparation commit
 *     ahead of this migration.
 * ---------------------------------------------------------------------
 * TimelineEventRecorder::record() itself is deliberately NOT made to
 * self-wrap (it is already correctly called, unwrapped, from six other
 * call sites that each already wrap their own containing method —
 * MatterOpeningService::openMatter(), LeadConversionService::convert(),
 * ManualPaymentService::submit(), PaymentPlanService::logEvent(),
 * DocumentChaseService::logEvent(), InvoiceDraftingService::send() —
 * self-wrapping record() would nest inside those already-active
 * contexts, reproducing the "decoy wrap" bug class this mission has
 * already found and fixed twice). Instead, each of the 8 broken call
 * sites establishes or extends its OWN wrap around its own record()
 * call:
 *
 *   1. ConflictCheckService::run() — narrow, sequential wrap around the
 *      $this->timeline->record() call.
 *   2. InvoiceDraftingService::draftFromTimeEntries() — same treatment.
 *   3. InvoiceDraftingService::createFlatFee() — same treatment.
 *   4. KeyDestructionExecutionService::execute() — THE SECURITY-
 *      CRITICAL FIX. Prior to this fix, the status update
 *      ($fresh->update(['status' => Executed, ...])) and the audit-
 *      trail record() call happened AFTER EncryptionKeyService::
 *      destroy() had already irreversibly destroyed the encryption
 *      keys and returned with its own context cleared, with no
 *      enclosing transaction around the tail of the method. Under
 *      FORCE, with ambient context already cleared, the record() call
 *      would throw — meaning the irreversible key destruction succeeds,
 *      the request status silently updates to Executed (autocommit,
 *      key_destruction_requests is not itself FORCE-protected), but the
 *      audit-trail entry for a security-critical, irreversible action
 *      is NEVER WRITTEN, and the caller receives an unhandled
 *      exception. This is precisely the failure KeyDestructionExecutionService's
 *      own docblock says must never happen ("Fully audited via
 *      TimelineEventRecorder"). The fix wraps the tail of the method
 *      (status update + record() call, established sequentially AFTER
 *      destroy() has already returned — never nested inside destroy()'s
 *      own wrap) in one context, so the status update and the audit
 *      record now happen atomically together.
 *   5. PaymentPlanDunningService::logAndReturn() — whole-method wrap
 *      (also closes a pre-existing, Checkpoint-23-disclosed unwrapped
 *      payment_plan_events write in this same method, as a byproduct of
 *      correctly wrapping the method this checkpoint already needed to
 *      touch, not a scope expansion).
 *   6. PaymentPlanInstallmentService::markMissed() — whole-method wrap,
 *      same payment_plan_events byproduct closure, wrap extends through
 *      the trailing ->fresh() re-read.
 *   7. PaymentPlanInstallmentService::markWaived() — same treatment.
 *   8. WebhookReplayService::replay() — narrow wrap around only the
 *      timeline->record() call; the method's other writes
 *      (webhook_deliveries, security_events) are deliberately left
 *      untouched, out of this checkpoint's scope.
 *
 * Also fixed: database/factories/TimelineEventFactory.php received the
 * same context-hold create() override already shipped for the six
 * prior nullable-firm_id factories (groups bare-created models by their
 * resolved firm_id and holds the matching tenant context, or clears it
 * for the null-firm_id group, around each group's own store() call) — a
 * purely forward-looking, symmetric fix, since no test currently
 * exercises this factory with a null firm_id (TimelineEventFactory
 * always resolves a real Firm::factory() by default, matching
 * record()'s own non-nullable-Firm contract).
 *
 * All 8 fixes were verified 47/47 across the 6 touched services' own
 * test files, and 1473/1473 across the full RlsForceRollout regression
 * sweep, ahead of this migration landing.
 *
 * ---------------------------------------------------------------------
 * Zero unrelated tables touched.
 * ---------------------------------------------------------------------
 * This migration alters ONLY timeline_events' FORCE flag. It does not
 * touch payment_plan_events' own FORCE state (already forced,
 * independently, at Checkpoint 23) even though two of the eight fixed
 * call sites incidentally also close a payment_plan_events wrapping gap
 * as a byproduct of correctly wrapping methods this checkpoint already
 * needed to touch.
 *
 * ---------------------------------------------------------------------
 * Known gaps NOT fixed in this batch (stated plainly, not hidden):
 * ---------------------------------------------------------------------
 *   - Residual, pre-existing (not introduced or worsened by this fix)
 *     inconsistency window in KeyDestructionExecutionService::execute():
 *     if record() throws for a reason OTHER than missing context after
 *     this fix lands, the transaction rollback correctly reverts the
 *     request's status back to Approved, but the encryption keys have
 *     already been irreversibly destroyed outside the wrap (by design —
 *     EncryptionKeyService::destroy() must stay sequential-not-nested,
 *     called before the new wrap begins). This fix makes the window
 *     dramatically smaller (from "guaranteed on every invocation under
 *     FORCE" to "only on a genuine secondary infra failure").
 *     Restructuring destroy()'s own transaction boundary is out of this
 *     checkpoint's scope. destroy() itself throws loudly on a second
 *     invocation once keys are already destroyed, providing a
 *     reasonable accidental safety net against silent re-destruction on
 *     retry.
 *   - WebhookReplayService::replay()'s webhook_deliveries and
 *     security_events writes remain unwrapped — deliberately out of
 *     this checkpoint's scope (webhook_deliveries is untouched by this
 *     arc; security_events is this arc's own eighth and final table,
 *     not yet started).
 *   - No validation that a timeline_events row's subject (subject_type/
 *     subject_id) actually belongs to the same firm as its own firm_id
 *     column — the same accepted "RLS only checks this row's own
 *     firm_id" boundary as every prior checkpoint.
 *   - TenantContextService::runWithFirmContext() lacks the save/restore
 *     mechanism its sibling runWithoutFirmContext() already has —
 *     tracked separately (task_d1c89b0c), not unique to timeline_events,
 *     and this checkpoint's own central design decision (record() must
 *     not self-wrap) is a direct, deliberate mitigation of that class of
 *     bug, not merely a disclosure of it.
 *
 * Full design dossier, including the exhaustive 18-call-site grep sweep
 * and both design reviewers' independent verification:
 * rls-checkpoints/39a3l/B6-timeline_events-design-dossier.md
 *
 * down() restores exactly the pre-migration state: FORCE disabled, the
 * original policy completely untouched throughout (it was never
 * dropped or recreated by up() in the first place).
 */
return new class extends Migration
{
    private const TABLE = 'timeline_events';

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
