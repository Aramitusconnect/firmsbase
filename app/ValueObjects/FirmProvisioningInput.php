<?php

namespace App\ValueObjects;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmOrganizationProvisioningMode;

/**
 * FirmProvisioningInput — the single typed input FirmProvisioningService::provision()
 * accepts, built from the Provision Firm wizard's submitted `array $data`
 * (never passed through as a raw array itself). Every property maps
 * directly to a real column/enum discovered on Firm/Organization/User/
 * FirmUser/FirmLicense/Plan — no invented field.
 *
 * `idempotencyKey` is generated once by the wizard when it mounts (a
 * fresh UUID held in Livewire component state, submitted as a hidden
 * field) — never regenerated on a retry within the same wizard session,
 * so a double-click or a resubmit-after-timeout carries the identical
 * key.
 */
final readonly class FirmProvisioningInput
{
    public function __construct(
        public string $idempotencyKey,
        public string $firmName,
        public ?string $legalName,
        public FirmOrganizationProvisioningMode $organizationMode,
        public ?int $organizationId,
        public ?string $newOrganizationName,
        public string $ownerName,
        public string $ownerEmail,
        public bool $reuseExistingUser,
        public CustomerType $customerType,
        public DeploymentMode $deploymentMode,
        public ?int $planId,
        public ?int $trialDaysOverride,
        public ?string $note,
    ) {}

    /**
     * A stable hash of every field that defines WHAT is being
     * provisioned — used to detect a changed request improperly reusing
     * an old idempotency key. Deliberately excludes nothing sensitive
     * (there is no password/token on this DTO to begin with).
     */
    public function payloadHash(): string
    {
        return hash('sha256', json_encode([
            $this->firmName,
            $this->legalName,
            $this->organizationMode->value,
            $this->organizationId,
            $this->newOrganizationName,
            $this->ownerName,
            $this->ownerEmail,
            $this->reuseExistingUser,
            $this->customerType->value,
            $this->deploymentMode->value,
            $this->planId,
            $this->trialDaysOverride,
            $this->note,
        ], JSON_THROW_ON_ERROR));
    }
}
