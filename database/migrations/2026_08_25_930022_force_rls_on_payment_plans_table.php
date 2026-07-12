<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 22 — permanently activates FORCE ROW LEVEL
 * SECURITY for payment_plans.
 *
 * payment_plans is tenant-owned (firm_id NOT NULL, direct ownership,
 * standard existing policy created by this repo's Phase 3 preparation
 * migration (2026_07_06_700012_extend_row_level_security_to_phase_3_
 * tenant_tables.php) — payment_plans_tenant_isolation, USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint, no
 * separate WITH CHECK — unchanged by this migration). client_id is
 * NOT NULL on this table (unlike some sibling tables' nullable
 * relations), so the factory fix below matters even for the bare
 * default path. No unrelated table's schema needed to change.
 *
 * Required factory fix — PaymentPlanFactory: definition() used to
 * resolve firm_id and client_id via two INDEPENDENT Firm::factory()/
 * Client::factory() calls, risking a cross-firm mismatch on a bare
 * PaymentPlan::factory()->create(). Fixed using the same "one
 * authoritative firm, all nested tenant-owned models tied to it"
 * pattern already used by InvoiceFactory/MatterFactory: definition()
 * now creates one Firm up front and ties client_id to it via
 * Client::factory()->forFirm($firm). Also added the standard
 * context-hold create() override (identical pattern to
 * InvoiceFactory/TimeEntryFactory) so a bare
 * PaymentPlan::factory()->create() continues to work whether or not
 * the caller already has an ambient tenant context active.
 * PaymentPlanInstallmentFactory/PaymentPlanEventFactory needed no
 * change: neither payment_plan_installments nor payment_plan_events is
 * forced by this migration, and both already resolve their nested
 * PaymentPlan via PaymentPlan::factory(), which transparently goes
 * through the fixed create() override above when left bare.
 *
 * Required production fixes:
 *
 *   - PaymentPlanService (the only place a PaymentPlan's status
 *     transitions): create(), edit(), activate(), renegotiate(),
 *     cancel(), and markDefaulted() are each wrapped as a single
 *     runWithFirmContext() call spanning their entire body (existing
 *     inner DB::transaction() calls are left in place — per
 *     TenantContextService's own docblock, a DB::transaction() nested
 *     inside runWithFirmContext()'s own transaction correctly becomes a
 *     Postgres savepoint, the same pattern already relied upon since
 *     Checkpoint 20/21). create() keys context off its own $firm
 *     parameter; the other five key off $plan->firm_id, a plain
 *     in-memory scalar already loaded on the model, not a relation
 *     load, so no read-before-context-established trap exists.
 *     markCompletedIfAllInstallmentsPaid() is deliberately NOT
 *     self-wrapped: its only caller, PaymentApplicationService::
 *     applyToInstallment(), is itself only ever called from inside
 *     ManualPaymentService::submit()'s own whole-method
 *     runWithFirmContext() wrap (established at Checkpoint 39A-3H when
 *     payments was forced). Self-wrapping it here would be the exact
 *     nested "decoy wrap" bug this arc has repeatedly avoided — the
 *     inner wrap's finally block would clear the outer, still-needed
 *     context the instant markCompletedIfAllInstallmentsPaid()
 *     returns, breaking ManualPaymentService::submit()'s own subsequent
 *     reads (timeline recording, payment->fresh(), the afterCommit
 *     webhook dispatch).
 *
 *   - ImportApplyService::createRecordFor(): the ImportEntityType::
 *     PaymentPlan match arm created its PaymentPlan with no tenant
 *     context, unlike its FirmLead/Client/Matter/TimeEntry/Invoice
 *     sibling cases in the same match statement. Fixed with the
 *     identical (new TenantContextService())->runWithFirmContext($firm,
 *     fn () => PaymentPlan::create([...])) wrapping style already used
 *     by those siblings. No other case in the match statement was
 *     touched.
 *
 *   - CustomerSuccessHealthScoreService::compute(): the
 *     $firm->paymentPlans()->count() read is now wrapped in its own
 *     tight runWithFirmContext() call, matching this checkpoint's
 *     narrow scope. The sibling matters/clients/documents/invoices/
 *     payments counts immediately around it are a PRE-EXISTING,
 *     out-of-scope gap (those tables were force-activated in earlier
 *     checkpoints without this method ever being fixed, since it has no
 *     production caller today — see "known gap" below) — left
 *     untouched here rather than silently expanding this batch's scope
 *     to a second, unrelated set of tables.
 *
 *   - FirmCommandCenterAggregationService::snapshot(): the two
 *     PaymentPlanInstallment aggregate queries (installmentsDueCount,
 *     installmentsMissedCount) filter via whereHas('paymentPlan', ...
 *     firm_id ...) against payment_plans. Each is now wrapped
 *     independently and tightly in its own runWithFirmContext() call,
 *     matching every sibling count in the same snapshot() construct
 *     call (each of which already wraps its own count independently —
 *     these are sequential, independent activations via named
 *     constructor arguments, never nested). Before this fix, once
 *     forced, both counts would have silently returned 0 rather than
 *     erroring — a dashboard silent-zero-rows risk, not a crash.
 *
 * Explicitly NOT touched (verified safe, no change needed):
 *   - app/Services/ManualPaymentService.php — submit() already wraps
 *     its ENTIRE body (including the applyToInstallment() call that
 *     transitively writes to payment_plans via
 *     markCompletedIfAllInstallmentsPaid()) in one runWithFirmContext()
 *     call, established when payments was forced at Checkpoint 39A-3H.
 *     No code change required for payment_plans to work correctly here.
 *   - app/Services/PaymentApplicationService.php — applyToInstallment()
 *     has exactly one caller (ManualPaymentService::submit(), see
 *     above), which already supplies active context around the entire
 *     call; applyToInvoice() never touches payment_plans. No self-wrap
 *     added, matching the project's "wrap at the call site, not inside
 *     a helper that may already be nested" convention.
 *   - app/Services/ProductionPilotWorkflowService.php —
 *     createAndActivatePaymentPlan() calls
 *     PaymentPlanService::create() then ::activate() with no other
 *     self-wrapping call in between; now that both self-wrap
 *     independently (see above), this becomes two sequential,
 *     independent context activations — exactly the established
 *     TimeTrackingService::stop()-style pattern — and requires no
 *     wrapping of its own.
 *   - app/Services/PaymentPlanDunningService.php — investigated and
 *     found NOT safe to self-wrap as originally suggested:
 *     checkAndLog(Installment $installment, ...) is given no $firm (or
 *     other already-trusted-firm) parameter, and PaymentPlanInstallment
 *     itself carries no firm_id column — the only way to learn which
 *     firm a given installment belongs to is to read its parent
 *     PaymentPlan row, which is itself now FORCE-protected. There is no
 *     context to key a self-wrap off of without first reading a
 *     forced table with no context active, a genuine circular
 *     dependency (unlike every other call site in this batch, which
 *     always has an already-known, already-trusted Firm available).
 *     The already-committed PaymentPlanDunningServiceTest (since
 *     Checkpoint 11) explicitly documents and relies on checkAndLog()
 *     staying unwrapped, with the caller supplying context — the exact
 *     same convention this service's own ConsentService::isGranted()
 *     dependency already follows. Left unchanged; resolving this
 *     circularity (e.g. by adding a $firm parameter) would change this
 *     method's public signature and its test file, both out of this
 *     agent's scope this batch.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - CustomerSuccessHealthScoreService::compute()'s matters/clients/
 *     documents/invoices/payments counts remain unwrapped, exactly as
 *     they were before this checkpoint — a pre-existing gap unrelated
 *     to payment_plans, out of scope here. compute() has no production
 *     caller today (only tests and a governance mapping reference),
 *     which is why this has not yet surfaced as a live bug.
 *   - Cross-firm matter_id/invoice_id/supersedes_payment_plan_id on
 *     payment_plans — no validation they belong to the same firm as
 *     the plan itself; this is the same accepted "RLS only checks this
 *     row's own firm_id" boundary as every prior checkpoint.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'payment_plans';

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
