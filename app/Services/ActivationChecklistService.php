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
 *
 * Phase 5 addition: seedProductionReadinessItems() additively extends
 * the SAME checklist (approved decision — no second checklist table)
 * with the 12 go-live item_keys the master plan's Phase 5 Scope
 * requires (firm_profile, jurisdiction, practice_areas, plan_license,
 * payment_mode, ai_mode, users, portal, email_domain, consents,
 * templates, compliance_acknowledgments). allRequiredItemsSatisfied()
 * on ActivationChecklist needed NO changes to cover these — it already
 * checks every required item on the checklist, whichever phase added
 * it.
 */
class ActivationChecklistService
{
    /**
     * item_key => human label. Order matches the master plan's Phase 5
     * Scope bullet list exactly.
     *
     * @var array<string, string>
     */
    public const PRODUCTION_READINESS_ITEMS = [
        'firm_profile' => 'Firm profile is complete',
        'jurisdiction' => 'Jurisdiction is configured',
        'practice_areas' => 'At least one practice area is enabled',
        'plan_license' => 'A usable plan/license is assigned',
        'payment_mode' => 'Payment mode is configured',
        'ai_mode' => 'AI mode is configured (even if disabled)',
        'users' => 'At least one active user is assigned',
        'portal' => 'Client portal is configured',
        'email_domain' => 'Sending email domain is verified',
        'consents' => 'Communication consent text is confirmed',
        'templates' => 'Practice-area templates are installed and reviewed',
        'compliance_acknowledgments' => 'Compliance acknowledgments are recorded',
    ];

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

    /**
     * Phase 5 addition. Idempotent: only inserts item_keys from
     * PRODUCTION_READINESS_ITEMS that are not already present on the
     * firm's existing checklist — safe to call repeatedly (e.g. once
     * per go-live review) without creating duplicates or a second
     * checklist. Requires the checklist to already exist
     * (createChecklist() must have been called first, same as Phase 1
     * onboarding already requires).
     *
     * @return array<int, string> item_keys actually inserted by this call
     */
    public function seedProductionReadinessItems(Firm $firm): array
    {
        $checklist = $firm->activationChecklist;

        if (! $checklist) {
            throw new \RuntimeException('Firm has no activation checklist to seed production-readiness items onto.');
        }

        $existingKeys = $checklist->items()->pluck('item_key')->all();
        $inserted = [];

        foreach (self::PRODUCTION_READINESS_ITEMS as $itemKey => $label) {
            if (in_array($itemKey, $existingKeys, true)) {
                continue;
            }

            $checklist->items()->create([
                'item_key' => $itemKey,
                'label' => $label,
                'is_required' => true,
                'is_complete' => false,
            ]);

            $inserted[] = $itemKey;
        }

        return $inserted;
    }
}
