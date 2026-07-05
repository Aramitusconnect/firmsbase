<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;

/**
 * EmailAccessPolicyService — the single gate for the email module:
 *   1. Entitlement: EntitlementService::isEnabled($firmId, 'email') —
 *      the existing Phase 6 seeded module_catalog row. No new
 *      module_catalog migration is added (project rule).
 *   2. Permission: a FirmUserRole allowlist, not a generic ACL engine
 *      (project rule) — mirrors Phase 8's ApiAccessPolicyService
 *      pattern. FirmOwner/Attorney may connect/disconnect a mailbox or
 *      change its storage_mode; FirmOwner/Attorney/Paralegal/
 *      LegalAssistant may view/link captured mail.
 */
class EmailAccessPolicyService
{
    private const MAILBOX_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const MAIL_VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    public function __construct(private readonly EntitlementService $entitlementService)
    {
    }

    public function canUseEmail(int $firmId): bool
    {
        return $this->entitlementService->isEnabled($firmId, 'email');
    }

    public function canManageMailbox(FirmUser $actor): bool
    {
        return in_array($actor->role, self::MAILBOX_MANAGEMENT_ROLES, true);
    }

    public function canViewMail(FirmUser $actor): bool
    {
        return in_array($actor->role, self::MAIL_VIEW_ROLES, true);
    }
}
