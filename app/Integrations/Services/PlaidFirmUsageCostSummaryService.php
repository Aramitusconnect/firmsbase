<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Services\TenantContextService;
use Illuminate\Support\Collection;

/**
 * PlaidFirmUsageCostSummaryService — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2). Backs
 * `PlaidUsagePage`'s "Usage and estimated cost" table. Aggregates
 * `provider_billable_call_reservations` (Direct `BelongsToTenant`,
 * FORCE RLS — an ordinary firm-scoped query, no per-firm loop needed
 * here) grouped by product/billing_operation, honoring
 * `provider_rate_card_entries`' own "null cost is 'unknown,' never
 * coalesced to $0" discipline (checkpoint4-combined-design.md §1.3) in
 * every returned row — a null `estimated_customer_price_cents` sum
 * renders as "Unknown," never "$0.00."
 */
final class PlaidFirmUsageCostSummaryService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function summariesForFirm(int $firmId): Collection
    {
        return (new TenantContextService)->runWithFirmContext($firmId, function () use ($firmId) {
            $rows = ProviderBillableCallReservation::query()
                ->selectRaw(
                    'product, billing_operation, environment, status, unit, '.
                    'COUNT(*) as call_count, SUM(quantity) as total_quantity, '.
                    'SUM(estimated_customer_price_cents) as total_estimated_cents, '.
                    'SUM(CASE WHEN estimated_customer_price_cents IS NULL THEN 1 ELSE 0 END) as unpriced_count, '.
                    'MIN(reserved_at) as first_reserved_at, MAX(reserved_at) as last_reserved_at'
                )
                ->where('firm_id', $firmId)
                ->where('provider_key', ProviderKey::Plaid->value)
                ->groupBy('product', 'billing_operation', 'environment', 'status', 'unit')
                ->orderBy('product')
                ->orderBy('billing_operation')
                ->get();

            return $rows->map(fn ($row): array => [
                'id' => implode(':', [$row->product, $row->billing_operation, $row->environment, $row->status]),
                'product' => $row->product,
                'billing_operation' => $row->billing_operation,
                'environment' => $row->environment,
                'status' => $row->status,
                'call_count' => (int) $row->call_count,
                'total_quantity' => (int) $row->total_quantity,
                'unit' => $row->unit,
                // Only present a total when NO row in this group was
                // unpriced — a partial sum would silently understate
                // cost, which is worse than an honest "Unknown."
                'total_estimated_cents' => ((int) $row->unpriced_count) > 0 ? null : (int) $row->total_estimated_cents,
                'first_reserved_at' => $row->first_reserved_at,
                'last_reserved_at' => $row->last_reserved_at,
            ])->values();
        });
    }
}
