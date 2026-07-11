<?php

namespace App\Services;

use App\Enums\DowngradeCheckStatus;
use App\Enums\PlanLimitMetric;
use App\Enums\SeatClass;
use App\Enums\UsageRollupMetric;
use App\Models\Firm;
use App\Models\Plan;
use App\ValueObjects\DowngradeEvaluationResult;

/**
 * DowngradeEvaluationService — computed at read time, mirroring Phase
 * 5's ProductionReadinessResult pattern; nothing is persisted by this
 * service itself (evaluate() only READS seat usage, plan limits, and
 * usage rollups). Checks seat classes/counts (via
 * SeatEnforcementService), storage (via UsageRollupService's latest
 * StorageBytes rollup for the firm's billing account, skipped if no
 * rollup data exists yet for that account — never guessed), and module
 * access (via EntitlementService) for every module the CURRENT plan
 * grants that the NEW plan does not. AI/API/forms/e-signature/
 * integrations are all instances of "is this module currently enabled
 * AND does the target plan still grant it" — the module-access check
 * below is intentionally generic across module_catalog rather than one
 * hardcoded branch per module name, so it automatically covers every
 * module in module_catalog, not just the ones enumerated in the PDF.
 *
 * A non-safe result NEVER means the firm loses access to its legal
 * data — that is a completely separate concern owned by
 * PastDueBillingPolicyService/LegalDataAccessPolicyService.
 *
 * hotfix 01: the seat-class loop previously used SeatClass enum cases
 * as ARRAY KEYS (e.g. [SeatClass::Attorney => PlanLimitMetric::...]).
 * PHP cannot use an object (a backed enum case is an object) as an
 * array key — this fatals with "Cannot access offset of type
 * App\Enums\SeatClass on array" the instant the array literal is
 * evaluated. Fixed by using an array of [seatClass, metric] pairs and
 * destructuring in the foreach, which never places an enum in a key
 * position. No downgrade rule changed, no PlanLimitMetric or SeatClass
 * value changed — this is a syntax/data-shape fix only.
 */
class DowngradeEvaluationService
{
    public function __construct(
        private SeatEnforcementService $seatEnforcement,
        private EntitlementService $entitlementService,
        private PlanLimitService $planLimitService,
        private UsageRollupService $usageRollupService,
    ) {
    }

    public function evaluate(Firm $firm, Plan $newPlan): DowngradeEvaluationResult
    {
        $seatFindings = [];
        $blockingReasons = [];

        foreach ([
            [SeatClass::Attorney, PlanLimitMetric::SeatsAttorney],
            [SeatClass::Staff, PlanLimitMetric::SeatsStaff],
            [SeatClass::ReadOnly, PlanLimitMetric::SeatsReadOnly],
        ] as [$seatClass, $metric]) {
            $usage = $this->seatEnforcement->usageFor($firm, $seatClass);
            $newLimit = $this->planLimitService->limitValue($newPlan, $metric);

            $seatFindings[$seatClass->value] = [
                'used' => $usage->used,
                'new_limit' => $newLimit,
            ];

            if ($newLimit !== null && $usage->used > $newLimit) {
                $blockingReasons[] = "seat class {$seatClass->value} in use ({$usage->used}) exceeds the new plan's limit ({$newLimit})";
            }
        }

        if (! empty($blockingReasons)) {
            return DowngradeEvaluationResult::blocked(
                DowngradeCheckStatus::BlockedSeatOveruse,
                $blockingReasons,
                $seatFindings,
            );
        }

        $storageLimitGb = $this->planLimitService->limitValue($newPlan, PlanLimitMetric::StorageGb);

        if ($storageLimitGb !== null && $firm->billing_account_id) {
            $usedBytes = $this->usageRollupService->totalForMetric(
                $firm->billingAccount,
                UsageRollupMetric::StorageBytes,
                now()->subMonth(),
                now(),
            );

            $usedGb = (int) ceil($usedBytes / 1_000_000_000);

            if ($usedGb > $storageLimitGb) {
                return DowngradeEvaluationResult::blocked(
                    DowngradeCheckStatus::BlockedStorageOveruse,
                    ["storage in use ({$usedGb} GB) exceeds the new plan's limit ({$storageLimitGb} GB)"],
                    $seatFindings,
                );
            }
        }

        $newPlan->loadMissing('modules');
        $newlyGrantedModules = $newPlan->modules->where('enabled', true)->pluck('module_code')->all();

        $moduleBlockingReasons = [];

        // Section 39A-3L, Checkpoint 4 - firm_entitlements now has FORCE
        // ROW LEVEL SECURITY active. This is a direct read against
        // firm_entitlements independent of EntitlementService::resolve()
        // (it reads the raw rows to enumerate currently-enabled modules,
        // not to resolve precedence for one specific module), so it
        // needs its own whole-call wrap. Materialized here (not left as
        // a lazy relation query inside the foreach) so the wrap's
        // runWithFirmContext() call completes and clears before the
        // loop calls EntitlementService::isEnabled() below, which
        // self-wraps its own call.
        $currentEntitlements = (new TenantContextService())->runWithFirmContext(
            $firm,
            fn () => $firm->entitlements()->where('enabled', true)->get()
        );

        foreach ($currentEntitlements as $entitlement) {
            if (! in_array($entitlement->module_code, $newlyGrantedModules, true)
                && $this->entitlementService->isEnabled($firm->id, $entitlement->module_code)) {
                $moduleBlockingReasons[] = "module '{$entitlement->module_code}' is currently enabled and in use but is not granted by the new plan";
            }
        }

        if (! empty($moduleBlockingReasons)) {
            return DowngradeEvaluationResult::blocked(
                DowngradeCheckStatus::BlockedModuleInUse,
                $moduleBlockingReasons,
                $seatFindings,
            );
        }

        return DowngradeEvaluationResult::safe();
    }
}
