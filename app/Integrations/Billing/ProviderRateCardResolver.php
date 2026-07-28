<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderRateCardEntry;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ProviderRateCardResolver — pipeline step 6
 * (checkpoint4-design-cost-control.md §2 step 6). Precedence resolution
 * (`platform_default` < `package_default` < `firm_override`) mirrors
 * `App\Services\EntitlementService::resolve()`'s own sort-by-precedence
 * shape verbatim — every candidate row whose `[effective_from,
 * effective_to)` window contains `$asOf` is fetched, then the
 * highest-precedence match wins via `RateCardScope::precedence()`.
 * Returns `null` (not a zeroed object) when literally no row exists at
 * any applicable scope — the pipeline still proceeds with no price
 * attached (design §1.3), it never fabricates a $0 rate.
 *
 * `provider_rate_card_entries` is Global/no-RLS, so resolving its own
 * candidate rows needs no tenant context. Resolving "the firm's current
 * package" DOES need tenant context, since it reads `firm_licenses`
 * (Direct `BelongsToTenant` + FORCE RLS) — this is
 * `App\Services\EmployeeRateService`'s own established "self-wrap only
 * the tenant-owned read" discipline, not one giant wrap around the
 * whole resolver.
 *
 * JUDGMENT CALL (checkpoint4-design-cost-control.md §9 item 3, already
 * flagged by the source design as its own judgment call, implemented
 * here): "package" is resolved as the firm's most recently created
 * `firm_licenses` row's `plan_id` — `App\Models\Firm` has no existing
 * `currentPlan()`/`currentLicense()` accessor to reuse, so this
 * resolver adds its own small, private, read-only lookup rather than
 * inventing a new concept on the `Firm` model itself, matching the
 * source design's own "reuse the existing `Plan`/`PlanModule` FVACC
 * billing concept" conclusion.
 */
final class ProviderRateCardResolver
{
    public function resolve(
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        string $environment,
        Firm $firm,
        ?Carbon $asOf = null,
    ): ?ProviderRateCardEntry {
        $asOf ??= now();

        $packagePlanId = $this->resolveFirmPlanId($firm);

        $candidates = ProviderRateCardEntry::query()
            ->where('provider_key', $providerKey->value)
            ->where('product', $classification->product)
            ->where('billing_operation', $classification->billingOperation)
            ->where('environment', $environment)
            ->where('effective_from', '<=', $asOf)
            ->where(function ($query) use ($asOf) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $asOf);
            })
            ->where(function ($query) use ($firm, $packagePlanId) {
                $query->where(function ($q) {
                    $q->where('scope_type', RateCardScope::PlatformDefault->value)->whereNull('scope_id');
                });

                if ($packagePlanId !== null) {
                    $query->orWhere(function ($q) use ($packagePlanId) {
                        $q->where('scope_type', RateCardScope::PackageDefault->value)->where('scope_id', $packagePlanId);
                    });
                }

                $query->orWhere(function ($q) use ($firm) {
                    $q->where('scope_type', RateCardScope::FirmOverride->value)->where('scope_id', $firm->id);
                });
            })
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortByDesc(fn (ProviderRateCardEntry $entry) => RateCardScope::from($entry->scope_type)->precedence())
            ->first();
    }

    private function resolveFirmPlanId(Firm $firm): ?int
    {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('firm_licenses')
                ->where('firm_id', $firm->id)
                ->whereNotNull('plan_id')
                ->orderByDesc('id')
                ->value('plan_id');
        });
    }
}
