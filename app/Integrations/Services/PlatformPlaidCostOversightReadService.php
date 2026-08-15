<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderRateCardEntry;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformPlaidCostOversightReadService — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Backs
 * `PlaidCostOversightPage`. Aggregates
 * `provider_billable_call_reservations` (Direct `BelongsToTenant`,
 * FORCE RLS) **BY FIRM, never by individual transaction** — every
 * number returned is a SUM/COUNT, never a drill-down into what was
 * purchased. "Unallocated usage" = reservations with
 * `rate_card_entry_id IS NULL`, summed by firm (§1.3's null-cost
 * discipline — an unpriced reservation's dollar total is never
 * coalesced to 0, it is counted separately as "unallocated").
 */
final class PlatformPlaidCostOversightReadService
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessIntegrationOversight($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access Plaid cost oversight.');
        }
    }

    /**
     * @param  ?string  $from  inclusive lower bound on `reserved_at` (Y-m-d or any Carbon-parseable string)
     * @param  ?string  $to  inclusive upper bound on `reserved_at`
     * @return Collection<int, array<string, mixed>>
     */
    public function overviewByFirm(PlatformAdmin $admin, ?string $from = null, ?string $to = null): Collection
    {
        $this->assertCanAccess($admin);

        // Prompt 2 (Integration Operations) addition: an optional
        // reserved_at window. Both parameters default to null, so every
        // existing caller keeps its original all-time behaviour
        // byte-for-byte — this is additive, never a change to the
        // established contract. The bounds are applied in SQL against
        // `reserved_at`, which already carries a composite index
        // (firm_id, status, reserved_at), so a windowed read is not a
        // full scan.
        $fromAt = filled($from) ? CarbonImmutable::parse($from)->startOfDay() : null;
        $toAt = filled($to) ? CarbonImmutable::parse($to)->endOfDay() : null;

        $applyWindow = function ($query) use ($fromAt, $toAt) {
            if ($fromAt !== null) {
                $query->where('reserved_at', '>=', $fromAt);
            }

            if ($toAt !== null) {
                $query->where('reserved_at', '<=', $toAt);
            }

            return $query;
        };

        $firms = Firm::query()->orderBy('name')->orderBy('id')->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $firmRow = $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $applyWindow): ?array {
                $allocated = $applyWindow(ProviderBillableCallReservation::query()
                    ->where('firm_id', $firm->id)
                    ->where('provider_key', ProviderKey::Plaid->value)
                    ->whereNotNull('rate_card_entry_id'))
                    ->selectRaw('COUNT(*) as call_count, SUM(estimated_customer_price_cents) as total_cents')
                    ->first();

                $unallocated = $applyWindow(ProviderBillableCallReservation::query()
                    ->where('firm_id', $firm->id)
                    ->where('provider_key', ProviderKey::Plaid->value)
                    ->whereNull('rate_card_entry_id'))
                    ->count();

                $liveBalanceCalls = $applyWindow(ProviderBillableCallReservation::query()
                    ->where('firm_id', $firm->id)
                    ->where('provider_key', ProviderKey::Plaid->value)
                    ->where('product', 'balance')
                    ->where('billing_operation', 'get'))
                    ->count();

                $totalCalls = (int) ($allocated->call_count ?? 0) + $unallocated;

                if ($totalCalls === 0) {
                    return null;
                }

                return [
                    'firm_uuid' => $firm->uuid,
                    'firm_name' => $firm->name,
                    'allocated_call_count' => (int) ($allocated->call_count ?? 0),
                    'estimated_customer_cost_cents' => $allocated->total_cents !== null ? (int) $allocated->total_cents : null,
                    'unallocated_call_count' => $unallocated,
                    'live_balance_call_count' => $liveBalanceCalls,
                    'total_call_count' => $totalCalls,
                ];
            });

            if ($firmRow !== null) {
                $rows->push($firmRow);
            }
        }

        return $rows->values();
    }

    /**
     * Pricing provenance for the Plaid cost estimate (Prompt 2,
     * Integration Operations §57). The cost figures this service returns
     * are ESTIMATES derived from `provider_rate_card_entries` — they are
     * not, and must never be presented as, an invoice. This method
     * exposes the facts a reader needs to judge how much to trust them:
     * which currency the rate cards are denominated in, how many rate
     * card entries are currently in effect, and when the effective
     * pricing last changed.
     *
     * Returns explicit nulls (never a fabricated default currency, never
     * a zero) when no rate card exists at all — the caller renders
     * "Pricing unavailable" for that case rather than "$0.00", which
     * would read as "this costs nothing".
     *
     * `provider_rate_card_entries` is global platform pricing reference
     * data, not tenant-owned, so this reads it directly with no tenant
     * context — matching how ProviderRateCardResolver itself reads it.
     *
     * @return array{currencies: array<int, string>, entry_count: int, effective_from: ?string, has_pricing: bool}
     */
    public function pricingProvenance(PlatformAdmin $admin): array
    {
        $this->assertCanAccess($admin);

        $entries = ProviderRateCardEntry::query()
            ->where('provider_key', ProviderKey::Plaid->value)
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', now());
            })
            ->get(['currency', 'effective_from']);

        $currencies = $entries
            ->pluck('currency')
            ->filter(fn (mixed $currency): bool => is_string($currency) && trim($currency) !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $effectiveFrom = $entries
            ->pluck('effective_from')
            ->filter()
            ->max();

        return [
            'currencies' => $currencies,
            'entry_count' => $entries->count(),
            'effective_from' => $effectiveFrom?->toDateTimeString(),
            'has_pricing' => $entries->isNotEmpty(),
        ];
    }
}
