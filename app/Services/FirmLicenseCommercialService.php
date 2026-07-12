<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Enums\LicenseStatus;
use App\Models\FirmLicense;
use App\Models\LicenseEvent;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FirmLicenseCommercialService — assigns commercial data (plan,
 * org_license, billing_mode) onto an EXISTING firm_licenses row and
 * keeps firm_entitlements in sync via EntitlementPlanSyncService. Every
 * assignment/status change writes a license_events row.
 *
 * Section 39A-3L, Checkpoint 19 — firm_licenses is FORCE-RLS protected
 * as of this checkpoint. assignPlan() deliberately does NOT wrap its
 * entire body in one outer runWithFirmContext() call:
 * EntitlementPlanSyncService::syncOrgInheritedEntitlements()/
 * syncPlanEntitlements() call EntitlementService::setForSource(), which
 * already self-wraps its own whole body (its own docblock requires
 * callers not to wrap it). A single outer wrap here would let that
 * inner self-wrap's finally clear the outer wrap's context the instant
 * it returns, silently breaking context for the remaining code (the
 * decoy-wrap bug this arc has fixed before). Instead, each actual
 * firm_licenses read/write gets its own tightly-scoped wrap, with the
 * self-wrapping sync call and the (unprotected) LicenseEvent write left
 * outside any wrap in between.
 */
class FirmLicenseCommercialService
{
    public function __construct(private EntitlementPlanSyncService $entitlementPlanSync)
    {
    }

    public function assignPlan(
        FirmLicense $license,
        Plan $plan,
        ?OrgLicense $orgLicense = null,
        ?BillingMode $billingMode = null,
        ?User $actor = null,
    ): FirmLicense {
        return DB::transaction(function () use ($license, $plan, $orgLicense, $billingMode, $actor) {
            (new TenantContextService())->runWithFirmContext($license->firm, function () use ($license, $plan, $orgLicense, $billingMode) {
                $license->update([
                    'plan_id' => $plan->id,
                    'org_license_id' => $orgLicense?->id,
                    'billing_mode' => $billingMode ?? $license->billing_mode,
                ]);
            });

            LicenseEvent::create([
                'licensable_type' => FirmLicense::class,
                'licensable_id' => $license->id,
                'event_type' => 'plan_assigned',
                'actor_type' => $actor ? User::class : 'System',
                'actor_id' => $actor?->id,
                'metadata' => ['plan_id' => $plan->id, 'org_license_id' => $orgLicense?->id],
            ]);

            if ($orgLicense) {
                $this->entitlementPlanSync->syncOrgInheritedEntitlements($license->firm, $orgLicense, $actor);
            } else {
                $this->entitlementPlanSync->syncPlanEntitlements($license->firm, $plan, $actor);
            }

            return (new TenantContextService())->runWithFirmContext($license->firm, fn () => $license->fresh());
        });
    }

    public function changeStatus(
        FirmLicense $license,
        LicenseStatus $status,
        ?string $reason = null,
        ?User $actor = null,
    ): FirmLicense {
        return DB::transaction(function () use ($license, $status, $reason, $actor) {
            $fromStatus = $license->license_status;

            (new TenantContextService())->runWithFirmContext($license->firm, function () use ($license, $status) {
                $license->update(['license_status' => $status]);
            });

            LicenseEvent::create([
                'licensable_type' => FirmLicense::class,
                'licensable_id' => $license->id,
                'event_type' => 'status_changed',
                'from_status' => $fromStatus->value,
                'to_status' => $status->value,
                'reason' => $reason,
                'actor_type' => $actor ? User::class : 'System',
                'actor_id' => $actor?->id,
            ]);

            return (new TenantContextService())->runWithFirmContext($license->firm, fn () => $license->fresh());
        });
    }
}
