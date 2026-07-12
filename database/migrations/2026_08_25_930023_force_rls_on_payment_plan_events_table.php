<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 23 — permanently activates FORCE ROW LEVEL
 * SECURITY for payment_plan_events.
 *
 * payment_plan_events is tenant-owned (firm_id NOT NULL, carried
 * directly on the row rather than only reachable via a join to its
 * parent payment_plan — same reasoning as every other "_events" table
 * in this codebase), append-only (PaymentPlanEvent::UPDATED_AT = null),
 * RLS-enabled with a standard existing policy created by this repo's
 * Phase 3 preparation migration (2026_07_06_700012_extend_row_level_
 * security_to_phase_3_tenant_tables.php) — payment_plan_events_tenant_
 * isolation, USING firm_id = NULLIF(current_setting('app.current_firm_
 * id', true), '')::bigint, no separate WITH CHECK — unchanged by this
 * migration. payment_plan_id is also NOT NULL. No unrelated table's
 * schema needed to change.
 *
 * Verified writer inventory (grep -rln "PaymentPlanEvent" app/): only
 * the model, PaymentPlan::events() (a plain hasMany relationship
 * definition, not a writer), DataModelContractMappingService.php (a
 * read-only governance docblock reference, not a writer), and two
 * actual writers:
 *
 *   - PaymentPlanService::logEvent() (private) — called exclusively
 *     from create(), activate(), renegotiate() (twice, for both the
 *     old and new plan), cancel(), and markDefaulted(), every one of
 *     which already wraps its ENTIRE body in its own
 *     runWithFirmContext() call (established at Checkpoint 22 when
 *     payment_plans was forced), and from
 *     markCompletedIfAllInstallmentsPaid(), which is deliberately left
 *     unwrapped per its own Checkpoint 22 docblock because its only
 *     caller already supplies context (see next point).
 *   - PaymentApplicationService::applyToInstallment() — creates a
 *     payment_plan_events row directly (the 'installment_paid' event)
 *     and also calls PaymentPlanService::markCompletedIfAllInstallments
 *     Paid(), which may append a second ('completed') row. This
 *     method's only caller is ManualPaymentService::submit() (grep -rn
 *     "applyToInstallment" app/ confirms no other call site), which
 *     wraps its entire body — including the applyToInstallment() call
 *     — in one runWithFirmContext() call established when payments was
 *     forced at Checkpoint 39A-3H. Both writes above therefore already
 *     execute under active, correct firm context today.
 *
 * Conclusion: no production service required any wiring change for
 * this checkpoint. This migration and the factory fix below are the
 * only production-scope changes in this batch.
 *
 * Required factory fix — PaymentPlanEventFactory: definition() used to
 * resolve firm_id and payment_plan_id via two INDEPENDENT
 * Firm::factory()/PaymentPlan::factory() calls, risking a cross-firm
 * mismatch on a bare PaymentPlanEvent::factory()->create() (both
 * columns are NOT NULL). Fixed using the same "one authoritative firm,
 * all nested tenant-owned models tied to it" pattern already used by
 * PaymentPlanFactory (Checkpoint 22): definition() now creates one Firm
 * up front and ties payment_plan_id to it via
 * PaymentPlan::factory()->forFirm($firm). The existing forPlan($plan)
 * state helper already tied both columns correctly for an
 * explicitly-passed plan and needed no change. Also added the standard
 * context-hold create() override (identical pattern to
 * PaymentPlanFactory/InvoiceFactory/TimeEntryFactory) so a bare
 * PaymentPlanEvent::factory()->create() continues to work whether or
 * not the caller already has an ambient tenant context active — no
 * bare PaymentPlanEvent::factory()->create() call exists in tests today
 * (grep -rn "PaymentPlanEvent::factory()->create()" tests/ returns
 * nothing), but this is this mission's established universal safety
 * net applied regardless of current usage.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - No validation that a payment_plan_events row's payment_plan_id
 *     actually belongs to the same firm as its own firm_id column —
 *     the same accepted "RLS only checks this row's own firm_id"
 *     boundary as every prior checkpoint. Both production writers above
 *     always derive firm_id directly from $plan->firm_id, so this gap
 *     has no known live trigger today; it is a database-layer
 *     constraint gap, not a demonstrated bug.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'payment_plan_events';

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
