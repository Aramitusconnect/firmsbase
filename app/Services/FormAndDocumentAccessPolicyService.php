<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;

/**
 * FormAndDocumentAccessPolicyService — entitlement gate reuses the
 * EXISTING Phase 6 seeded module_catalog codes 'forms' and
 * 'document_generation' — no new module_catalog row added. Permission
 * checks are FirmUserRole allowlists, not a generic ACL engine.
 */
class FormAndDocumentAccessPolicyService
{
    private const APPROVAL_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const GENERATION_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function canUseForms(int $firmId): bool
    {
        return $this->entitlementService->isEnabled($firmId, 'forms');
    }

    public function canUseDocumentGeneration(int $firmId): bool
    {
        return $this->entitlementService->isEnabled($firmId, 'document_generation');
    }

    public function canGenerate(FirmUser $actor): bool
    {
        return in_array($actor->role, self::GENERATION_ROLES, true);
    }

    /**
     * Approval/rejection authority — attorney_review -> approved/
     * rejected/revised, per the approved workflow, is restricted to
     * FirmOwner/Attorney. No AI actor type exists, so this can never
     * be satisfied by an AI approval.
     */
    public function canApprove(FirmUser $actor): bool
    {
        return in_array($actor->role, self::APPROVAL_ROLES, true);
    }
}
