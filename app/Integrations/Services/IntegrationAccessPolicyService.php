<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use RuntimeException;

/**
 * IntegrationAccessPolicyService — the non-financial-tier integration
 * role gate (checkpoint-00-final-specification.md §17;
 * architecture.md §4's permission-tiering table). Covers every
 * non-financial provider category (Google, Microsoft, Dropbox/
 * OneDrive/SharePoint, and this mission's internal `test` provider).
 *
 * Mirrors WebhookAccessPolicyService's exact shape
 * (app/Services/WebhookAccessPolicyService.php) — a single-tier
 * management gate, no requester/approver split, since architecture.md
 * §4 draws that dual-approval distinction only for the FINANCIAL tier
 * (see FinancialIntegrationAccessPolicyService, a deliberately
 * separate class, never merged with this one behind an
 * `if ($isFinancial)` branch, per the frozen spec's explicit
 * instruction).
 *
 * Role ceilings, per architecture.md §4 (non-financial column):
 *   - Connect / configure / disconnect: FirmOwner, Attorney only.
 *   - View connection/health/activity: FirmOwner, Attorney, Paralegal,
 *     LegalAssistant.
 *   - View usage/billing impact: FirmOwner, BillingStaff — identical
 *     ceiling in both the financial and non-financial columns of
 *     architecture.md §4's table, so it lives here rather than being
 *     duplicated in the financial-tier service.
 *
 * Role-tier ceilings may only be NARROWED by policy, never widened by
 * entitlement (frozen rule, checkpoint-00-final-specification.md §17)
 * — Receptionist never appears in any allowlist below, full stop.
 */
class IntegrationAccessPolicyService
{
    private const MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const USAGE_VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::BillingStaff,
    ];

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canConnect(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function canConfigure(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function canDisconnect(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function canViewUsage(FirmUserRole $role): bool
    {
        return in_array($role, self::USAGE_VIEW_ROLES, true);
    }

    public function assertCanView(FirmUser $actor): void
    {
        if (! $this->canView($actor->role)) {
            throw new RuntimeException(
                'Only FirmOwner, Attorney, Paralegal, or LegalAssistant may view this integration connection.'
            );
        }
    }

    public function assertCanConnect(FirmUser $actor): void
    {
        if (! $this->canConnect($actor->role)) {
            throw new RuntimeException('Only FirmOwner or Attorney may connect a non-financial integration.');
        }
    }

    public function assertCanConfigure(FirmUser $actor): void
    {
        if (! $this->canConfigure($actor->role)) {
            throw new RuntimeException('Only FirmOwner or Attorney may configure a non-financial integration.');
        }
    }

    public function assertCanDisconnect(FirmUser $actor): void
    {
        if (! $this->canDisconnect($actor->role)) {
            throw new RuntimeException('Only FirmOwner or Attorney may disconnect a non-financial integration.');
        }
    }

    public function assertCanViewUsage(FirmUser $actor): void
    {
        if (! $this->canViewUsage($actor->role)) {
            throw new RuntimeException('Only FirmOwner or BillingStaff may view integration usage/billing impact.');
        }
    }
}
