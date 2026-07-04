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
            $license->update([
                'plan_id' => $plan->id,
                'org_license_id' => $orgLicense?->id,
                'billing_mode' => $billingMode ?? $license->billing_mode,
            ]);

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

            return $license->fresh();
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

            $license->update(['license_status' => $status]);

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

            return $license->fresh();
        });
    }
}
