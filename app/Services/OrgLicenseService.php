<?php

namespace App\Services;

use App\Enums\LicenseStatus;
use App\Models\LicenseEvent;
use App\Models\Organization;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OrgLicenseService — the only place org_licenses rows are created or
 * have their status changed. Every issue/status change writes a
 * license_events row (polymorphic licensable, shared with FirmLicense).
 * Issuing a license does NOT by itself sync entitlements to member
 * firms — that is EntitlementPlanSyncService::syncOrgInheritedEntitlements(),
 * called per-firm (typically by FirmLicenseCommercialService when a
 * firm is attached, or by an admin action iterating member firms).
 */
class OrgLicenseService
{
    public function issue(
        Organization $organization,
        Plan $plan,
        array $attributes = [],
        ?User $actor = null,
    ): OrgLicense {
        return DB::transaction(function () use ($organization, $plan, $attributes, $actor) {
            $license = OrgLicense::create(array_merge([
                'organization_id' => $organization->id,
                'plan_id' => $plan->id,
                'license_key' => $attributes['license_key'] ?? (string) Str::uuid(),
                'license_status' => LicenseStatus::Trial,
            ], $attributes));

            LicenseEvent::create([
                'licensable_type' => OrgLicense::class,
                'licensable_id' => $license->id,
                'event_type' => 'issued',
                'to_status' => $license->license_status->value,
                'actor_type' => $actor ? User::class : 'System',
                'actor_id' => $actor?->id,
                'metadata' => ['plan_id' => $plan->id],
            ]);

            return $license;
        });
    }

    public function changeStatus(
        OrgLicense $license,
        LicenseStatus $status,
        ?string $reason = null,
        ?User $actor = null,
    ): OrgLicense {
        return DB::transaction(function () use ($license, $status, $reason, $actor) {
            $fromStatus = $license->license_status;

            $license->update(['license_status' => $status]);

            LicenseEvent::create([
                'licensable_type' => OrgLicense::class,
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
