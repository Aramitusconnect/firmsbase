<?php

namespace App\Services;

use App\Enums\ActivationChecklistStatus;
use App\Enums\FirmActivationStatus;
use App\Enums\LicenseStatus;
use App\Enums\TenantEncryptionKeyStatus;
use App\Models\ActivationChecklist;
use App\Models\Firm;
use Illuminate\Support\Facades\DB;

/**
 * ActivationChecklistService — the ONLY place a firm is allowed to
 * transition draft/onboarding -> activated.
 *
 * Gate conditions, ALL required (fail-closed):
 *   1. firm.billing_account_id is set.
 *   2. firm.firmSettings exists.
 *   3. firm has at least one usable license (not cancelled/expired).
 *   4. the activation checklist exists and every required item is
 *      complete or explicitly waived.
 *   5. an active tenant encryption key has been provisioned.
 */
class ActivationChecklistService
{
    /**
     * @return array<int, string> empty array means the firm is eligible to activate
     */
    public function unmetRequirements(Firm $firm): array
    {
        $firm->loadMissing(['firmSettings', 'licenses', 'activationChecklist.items', 'tenantEncryptionKeys']);

        $unmet = [];

        if (is_null($firm->billing_account_id)) {
            $unmet[] = 'billing_account_missing';
        }

        if (! $firm->firmSettings) {
            $unmet[] = 'firm_settings_missing';
        }

        $hasUsableLicense = $firm->licenses->contains(
            fn ($license) => ! in_array($license->license_status, [
                LicenseStatus::Cancelled,
                LicenseStatus::Expired,
            ], true)
        );

        if (! $hasUsableLicense) {
            $unmet[] = 'usable_license_missing';
        }

        $checklist = $firm->activationChecklist;

        if (! $checklist) {
            $unmet[] = 'activation_checklist_missing';
        } elseif (! $checklist->allRequiredItemsSatisfied()) {
            $unmet[] = 'activation_checklist_incomplete';
        }

        $hasActiveKey = $firm->tenantEncryptionKeys->contains(
            fn ($key) => $key->status === TenantEncryptionKeyStatus::Active
        );

        if (! $hasActiveKey) {
            $unmet[] = 'tenant_encryption_key_missing';
        }

        return $unmet;
    }

    public function isEligible(Firm $firm): bool
    {
        return empty($this->unmetRequirements($firm));
    }

    /**
     * @throws \RuntimeException
     */
    public function activate(Firm $firm): Firm
    {
        $unmet = $this->unmetRequirements($firm);

        if (! empty($unmet)) {
            throw new \RuntimeException(
                'Firm cannot be activated. Unmet requirements: '.implode(', ', $unmet)
            );
        }

        return DB::transaction(function () use ($firm) {
            $firm->update(['activation_status' => FirmActivationStatus::Activated]);

            $checklist = $firm->activationChecklist;
            if ($checklist->status !== ActivationChecklistStatus::Completed) {
                $checklist->update([
                    'status' => ActivationChecklistStatus::Completed,
                    'completed_at' => now(),
                ]);
            }

            return $firm->fresh();
        });
    }

    /**
     * Create the (single, per-firm) activation checklist shell. Item
     * seeding is not done here — the caller supplies the item set
     * appropriate to the firm's customer_type/practice-area pack.
     */
    public function createChecklist(Firm $firm): ActivationChecklist
    {
        if ($firm->activationChecklist) {
            throw new \RuntimeException('Firm already has an activation checklist.');
        }

        return ActivationChecklist::create([
            'firm_id' => $firm->id,
            'status' => ActivationChecklistStatus::InProgress,
            'started_at' => now(),
        ]);
    }
}
