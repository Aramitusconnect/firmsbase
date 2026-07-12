<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 21 — permanently activates FORCE ROW LEVEL
 * SECURITY for time_entries.
 *
 * time_entries is tenant-owned (firm_id NOT NULL, direct ownership,
 * standard existing policy created by this repo's Phase 3 preparation
 * migration — unchanged by this migration). No unrelated table's schema
 * needed to change. This closes the asymmetry documented in Checkpoint
 * 20's migration, where time_tracking_sessions was forced but
 * time_entries deliberately was not yet.
 *
 * Required production fix — TimeEntryApprovalService (all 5 methods):
 * this service is the only place a TimeEntry's status transitions, and
 * the only place a manual (non-timer) entry is created; none of its
 * five methods established any tenant context before this checkpoint.
 * createManualEntry(), submit(), reject(), and markInvoiced() are each
 * wrapped as a single runWithFirmContext() call spanning their entire
 * body. createManualEntry() keys context off the $firm parameter
 * directly (no row exists yet to read a scalar from); submit(),
 * reject(), and markInvoiced() key off $entry->firm_id, a plain
 * in-memory scalar already loaded on the model, not a relation load, so
 * no read-before-context-established trap exists.
 *
 * approve() is the one method that is deliberately NOT wrapped as a
 * single whole-method call. It calls
 * EmployeeRateService::currentRateFor() to snapshot the employee's
 * current billing rate, and that method's own docblock documents that
 * it self-wraps its body in runWithFirmContext() on the explicit
 * assumption that its caller does not already have an outer context
 * active around the call. If approve() instead opened one outer context
 * spanning both the currentRateFor() call and the subsequent
 * $entry->update(), currentRateFor()'s inner self-wrap would clear that
 * outer context the instant it returned (runWithFirmContext()'s finally
 * block unconditionally clears both the PHP-memory and database
 * settings) — leaving the following update() to run with no context at
 * all. This is the same decoy-wrap bug class already fixed in
 * Checkpoints 19 and 20. The correct, narrow fix: call
 * currentRateFor() unwrapped, exactly as before, letting it continue to
 * self-wrap; then open a second, independent, tightly-scoped
 * runWithFirmContext() around only the update()+fresh() portion. These
 * are two separate context activations in sequence, never nested.
 *
 * markInvoiced() is called by InvoiceDraftingService::
 * draftFromTimeEntries() from inside that method's own outer
 * DB::transaction(). InvoiceDraftingService.php required NO changes in
 * this checkpoint: markInvoiced()'s new self-wrap transparently closes
 * the double-billing risk that existed there, because
 * runWithFirmContext()'s internal DB::transaction() correctly becomes a
 * Postgres savepoint when invoked from inside an already-open outer
 * transaction (see TenantContextService's own docblock), and its
 * explicit finally-block cleanup (rather than relying on transaction-end
 * auto-revert) makes this safe regardless of nesting. This is the same
 * nested-transaction-as-savepoint pattern already relied upon by
 * TimeTrackingService::stop() since Checkpoint 20. Before this fix, an
 * unwrapped UPDATE against a FORCE-protected time_entries row from
 * inside draftFromTimeEntries() would have silently affected zero rows
 * (FORCE RLS's USING/WITH CHECK filters it out, not an error), leaving
 * an entry eligible to be drafted onto a second invoice later — this
 * checkpoint's fix closes that without touching
 * app/Services/InvoiceDraftingService.php at all, worth stating clearly
 * so a future reader does not wonder why the highest-stakes file in the
 * audit wasn't touched.
 *
 * Required production fix — ImportApplyService::createRecordFor(): the
 * ImportEntityType::TimeEntry match arm created its TimeEntry with no
 * tenant context, unlike its four sibling cases (FirmLead, Client,
 * Matter, Invoice) in the same match statement. Fixed with the
 * identical (new TenantContextService())->runWithFirmContext($firm,
 * fn () => TimeEntry::create([...])) wrapping style already used by
 * those siblings. No other case in the match statement was touched.
 *
 * Required factory fix — TimeEntryFactory: added the standard
 * context-hold create() override (identical pattern to
 * TimeTrackingSessionFactory/FirmLicenseFactory) so a bare
 * TimeEntry::factory()->create() continues to work correctly whether or
 * not the caller already has an ambient tenant context active.
 *
 * Explicitly NOT touched (verified safe, no change needed):
 *   - app/Services/InvoiceDraftingService.php — see above.
 *   - app/Services/EmployeeRateService.php — both setRate() and
 *     currentRateFor() already correctly self-wrap; no code change.
 *   - app/Services/ImportDuplicateDetectionService.php — confirmed to
 *     never query time_entries at all for the TimeEntry entity type
 *     (returns noMatch() unconditionally); unrelated business-
 *     completeness gap, out of scope.
 *   - app/Services/TimeTrackingService.php — already correctly fixed in
 *     Checkpoint 20.
 *
 * Dormant, not-fixed findings (documented, not actioned this batch):
 *   - EmployeeRateService's known residual gap (no verification that
 *     $employee holds a firm_users membership for $firm) — pre-existing,
 *     unrelated to this checkpoint, already documented in that service's
 *     own docblock.
 *   - Cross-firm matter_id/client_id/time_tracking_session_id on
 *     time_entries — no validation they belong to the same firm as the
 *     entry itself; this is the same accepted "RLS only checks this
 *     row's own firm_id" boundary as every prior checkpoint.
 *   - ImportDuplicateDetectionService never checking TimeEntry for
 *     duplicates — a business-completeness gap, not a tenant-isolation
 *     concern, out of scope.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'time_entries';

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
