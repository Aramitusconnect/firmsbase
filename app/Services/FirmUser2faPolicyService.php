<?php

namespace App\Services;

use App\Enums\FirmUserStatus;
use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Support\Collection;

/**
 * FirmUser2faPolicyService — Section 39B backend policy. As of Section
 * 39A-3L, Checkpoint 18, User::canAccessPanel() is a live consumer:
 * it calls isRequiredForFirmUser() and isCompliant() to gate Filament
 * panel access, with both calls wrapped in a single
 * TenantContextService::runWithFirmContext() closure (required because
 * firm_settings became FORCE-RLS protected in that checkpoint, and no
 * ambient tenant context otherwise exists at that point in the auth
 * flow). This service still never enforces anything at a login ROUTE
 * (there is no dedicated login route/UI surface — Filament's own panel
 * middleware is what calls canAccessPanel()), never sends a
 * notification, and never generates a 2FA secret/recovery code.
 *
 * Reuses the EXISTING TwoFactorMode enum (Optional/Required/Disabled)
 * exactly as firm_settings.client_2fa_mode already does — no new enum
 * was needed. Reuses the EXISTING User.two_factor_confirmed_at column
 * (Laravel default scaffolding, already present) as the sole source of
 * "has confirmed 2FA" truth — FirmUser gains no new 2FA column of its
 * own; it only points to its owning User via the existing user_id
 * relationship.
 *
 * Policy (approved decisions): no role exemptions for the pilot — a
 * firm in Required mode requires 2FA confirmation from every one of
 * its ACTIVE (FirmUserStatus::Active) firm users regardless of role.
 * Invited/Suspended/Removed firm users never block readiness. A
 * FirmUser with no related User row is treated as non-compliant
 * whenever the firm requires 2FA (there is nothing to check, so it
 * cannot be assumed compliant).
 */
class FirmUser2faPolicyService
{
    public function isRequiredForFirm(Firm $firm): bool
    {
        $mode = $firm->firmSettings?->firm_user_2fa_mode;

        return $mode === TwoFactorMode::Required;
    }

    public function isRequiredForFirmUser(FirmUser $firmUser): bool
    {
        return $this->isRequiredForFirm($firmUser->firm);
    }

    public function isCompliant(FirmUser $firmUser): bool
    {
        if (! $this->isRequiredForFirmUser($firmUser)) {
            return true;
        }

        return $firmUser->user !== null && $firmUser->user->two_factor_confirmed_at !== null;
    }

    /**
     * Only ACTIVE firm users are ever considered — invited/suspended/
     * removed firm users never block pilot readiness.
     *
     * @return Collection<int, FirmUser>
     */
    public function nonCompliantFirmUsers(Firm $firm): Collection
    {
        return $firm->firmUsers()
            ->where('status', FirmUserStatus::Active->value)
            ->get()
            ->reject(fn (FirmUser $firmUser) => $this->isCompliant($firmUser))
            ->values();
    }

    public function firmIsReadyForPilotData(Firm $firm): bool
    {
        return $this->nonCompliantFirmUsers($firm)->isEmpty();
    }

    /**
     * @return array{mode: ?string, required: bool, active_firm_user_count: int, compliant_count: int, non_compliant_count: int, non_compliant_firm_user_ids: array<int, int>, ready_for_pilot_data: bool}
     */
    public function requirementSummary(Firm $firm): array
    {
        $activeFirmUsers = $firm->firmUsers()->where('status', FirmUserStatus::Active->value)->get();
        $nonCompliant = $this->nonCompliantFirmUsers($firm);

        return [
            'mode' => $firm->firmSettings?->firm_user_2fa_mode?->value,
            'required' => $this->isRequiredForFirm($firm),
            'active_firm_user_count' => $activeFirmUsers->count(),
            'compliant_count' => $activeFirmUsers->count() - $nonCompliant->count(),
            'non_compliant_count' => $nonCompliant->count(),
            'non_compliant_firm_user_ids' => $nonCompliant->pluck('id')->all(),
            'ready_for_pilot_data' => $nonCompliant->isEmpty(),
        ];
    }
}
