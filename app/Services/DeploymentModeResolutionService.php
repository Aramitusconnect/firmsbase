<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Models\Firm;

/**
 * DeploymentModeResolutionService — thin, read-only confirmation layer
 * over the EXISTING Phase 1 tenancy abstraction
 * (TenantContextResolver/TenantContext already carry deploymentMode).
 * This service does NOT introduce a second resolution path and never
 * branches core business logic on deployment mode itself — it only
 * answers narrow, Phase-16-specific questions (is this firm dedicated/
 * private, does its customer_type/deployment_mode combination need
 * further approval) by reading the firm's existing columns.
 * TenantContextResolver/TenantContext are untouched (protected files).
 */
class DeploymentModeResolutionService
{
    public function isDedicatedOrPrivate(Firm $firm): bool
    {
        return in_array($firm->deployment_mode, [DeploymentMode::Dedicated, DeploymentMode::PrivateEnterprise], true);
    }

    public function isDedicated(Firm $firm): bool
    {
        return $firm->deployment_mode === DeploymentMode::Dedicated;
    }

    public function isPrivateEnterprise(Firm $firm): bool
    {
        return $firm->deployment_mode === DeploymentMode::PrivateEnterprise;
    }

    /**
     * True only for the one combination that needs platform-admin
     * approval before it may be considered valid (project rule 17):
     * dedicated + legal_specialist. dedicated + law_firm and every
     * private_enterprise combination need no such gate from this
     * service (private_enterprise customer-type restrictions are
     * "subject to license settings" per the plan — enforced by
     * LicenseFileValidationService, not here).
     */
    public function requiresDedicatedCustomerTypeApproval(Firm $firm): bool
    {
        return $this->isDedicated($firm) && $firm->customer_type === CustomerType::LegalSpecialist;
    }
}
