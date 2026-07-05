<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;

/**
 * SignatureAndPdfAccessPolicyService — entitlement gate reuses the
 * EXISTING Phase 6 seeded module_catalog code 'e_signature' — no new
 * module_catalog row is added anywhere in Phase 11 (including for
 * annotations — see canManageAnnotations()). Permission checks are
 * FirmUserRole allowlists, not a generic ACL engine.
 */
class SignatureAndPdfAccessPolicyService
{
    private const MANAGE_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const ATTORNEY_REVIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function canUseSignatures(int $firmId): bool
    {
        return $this->entitlementService->isEnabled($firmId, 'e_signature');
    }

    public function canManageRequests(FirmUser $actor): bool
    {
        return in_array($actor->role, self::MANAGE_ROLES, true);
    }

    /**
     * The attorney-review sign-off gate ("E-signature is not a
     * substitute for legal review of whether a specific document can
     * be signed electronically") is restricted to FirmOwner/Attorney —
     * no AI actor type exists, so this can never be satisfied by an AI
     * approval.
     */
    public function canReviewAsAttorney(FirmUser $actor): bool
    {
        return in_array($actor->role, self::ATTORNEY_REVIEW_ROLES, true);
    }

    public function canVoid(FirmUser $actor): bool
    {
        return in_array($actor->role, self::ATTORNEY_REVIEW_ROLES, true);
    }

    /**
     * Annotations are gated behind the SAME e_signature entitlement's
     * settings_json — no new module_catalog row. Disabled unless a
     * firm's entitlement settings explicitly set annotations_enabled
     * to true.
     */
    public function annotationsEnabledForFirm(int $firmId): bool
    {
        $resolution = $this->entitlementService->resolve($firmId, 'e_signature');

        return $resolution->enabled && (bool) ($resolution->settings['annotations_enabled'] ?? false);
    }
}
