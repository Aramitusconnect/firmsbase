<?php

namespace App\Services;

use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderInvoiceReconciliation;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use DateTimeInterface;

/**
 * ProviderInvoiceReconciliationService — monthly provider invoice
 * reconciliation, modeled directly on
 * `App\Services\TrustReconciliationService::run()`'s own human-entered,
 * comparison-only, never-auto-correcting pattern
 * (checkpoint4-design-cost-control.md §6). PLATFORM-SCOPED, not
 * per-firm — `provider_invoice_reconciliations` itself is Global/no-RLS,
 * so the row this method writes needs no tenant context.
 *
 * RLS GAP FOUND AND FIXED (a judgment call this implementation had to
 * make, not resolved by the source design): `provider_billable_call_reservations`
 * — the table `$systemTotal` sums across — is Direct `BelongsToTenant` +
 * FORCE RLS (design §3.1/§10). The source design's own §6 code sample
 * shows a bare, unscoped `ProviderBillableCallReservation::query()->sum(...)`
 * call with no tenant-context wrap of any kind, as if it "just works"
 * platform-wide — but with FORCE RLS active and no `app.current_firm_id`
 * session setting active, `current_setting(..., true)` evaluates NULL,
 * `firm_id = NULL` never matches any row, and that query would silently
 * SUM TO ZERO for every firm, exactly the kind of "central aggregate
 * silently empty under RLS" defect this mission's own security reviews
 * have repeatedly caught elsewhere (mirrors the already-registered
 * `integration_platform_overview_summaries`/`integration_webhook_routing_index`
 * precedent: a genuine cross-firm platform aggregate is structurally
 * impossible against a FORCE-RLS'd tenant table without either a
 * SECURITY DEFINER function or per-firm iteration — no such bypass
 * mechanism exists in this codebase today, so this method iterates
 * every firm, summing under each firm's own `runWithFirmContext()`
 * scope, the same discipline `App\Jobs\RetentionSweepJob` already
 * establishes for per-firm-scoped platform work. Correct, at the cost
 * of one query per firm — acceptable for a monthly, human-triggered
 * PlatformAdmin action, never a hot path.
 *
 * `$systemTotal` sums `estimated_customer_price_cents` over
 * `finalized_billable` reservations in the period, EXCLUDING null-cost
 * rows (never zeroing them — design §1.3's "unknown, not free"
 * discipline). A discrepancy is recorded as-is and NEVER auto-corrected
 * by this or any other service — resolving one requires a separate,
 * deliberate `provider_rate_card_entries` edit, never a side effect of
 * running a reconciliation.
 */
class ProviderInvoiceReconciliationService
{
    public function __construct(private readonly TenantContextService $tenantContext) {}

    public function run(
        string $providerKey,
        PlatformAdmin $performedBy,
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd,
        int $assertedInvoiceTotalCents,
        ?string $notes = null,
    ): ProviderInvoiceReconciliation {
        $systemTotal = 0;

        Firm::query()->select('id')->orderBy('id')->chunkById(200, function ($firms) use (&$systemTotal, $providerKey, $periodStart, $periodEnd) {
            foreach ($firms as $firm) {
                $systemTotal += (int) $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $providerKey, $periodStart, $periodEnd) {
                    return (int) ProviderBillableCallReservation::query()
                        ->where('firm_id', $firm->id)
                        ->where('provider_key', $providerKey)
                        ->where('status', ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE)
                        ->whereBetween('finalized_at', [$periodStart, $periodEnd])
                        ->whereNotNull('estimated_customer_price_cents')
                        ->sum('estimated_customer_price_cents');
                });
            }
        });

        $discrepancy = $systemTotal - $assertedInvoiceTotalCents;

        return ProviderInvoiceReconciliation::create([
            'provider_key' => $providerKey,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'asserted_invoice_total_cents' => $assertedInvoiceTotalCents,
            'system_recorded_total_cents' => $systemTotal,
            'discrepancy_cents' => $discrepancy,
            'status' => $discrepancy === 0 ? ProviderInvoiceReconciliation::STATUS_BALANCED : ProviderInvoiceReconciliation::STATUS_DISCREPANCY,
            'performed_by' => $performedBy->id,
            'completed_at' => now(),
            'notes' => $notes,
        ]);
    }
}
