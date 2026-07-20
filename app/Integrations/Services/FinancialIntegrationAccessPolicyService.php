<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use RuntimeException;

/**
 * FinancialIntegrationAccessPolicyService — the financial-tier
 * integration role gate (checkpoint-00-final-specification.md §17;
 * architecture.md §4's permission-tiering table). Covers the financial
 * provider category (QuickBooks, LawPay, Stripe, Plaid).
 *
 * *** PURE SCAFFOLDING THIS CHECKPOINT — NO LIVE FINANCIAL PROVIDER OR
 * CREDENTIAL EXISTS YET. *** Exactly one provider is registered and
 * seeded anywhere in this mission — `test` (category `internal`,
 * see database/migrations/2026_09_01_010001_create_integration_providers_table.php)
 * — and zero `integration_providers` rows of category `financial`
 * exist. This class has no live/reachable financial capability behind
 * it at Checkpoint 3 (confirmed safe by checkpoint-03-security-review.md,
 * finding 6: "Policy-service scaffolding is safe... as long as it's
 * documented as scaffolding"). It exists purely as reviewed scaffolding
 * for a future, separately-authorized financial-provider add-on, per
 * checkpoint-00-final-specification.md §17 and §19's explicit
 * prohibition on any live Stripe/Plaid/LawPay/QuickBooks code,
 * credentials, routes, or SDKs in this mission. No code path in this
 * mission calls or reaches this class.
 *
 * Deliberately a SEPARATE class from IntegrationAccessPolicyService —
 * never one merged class with an `if ($isFinancial)` branch (frozen
 * spec, §17). Mirrors TrustAccessPolicyService's real, proven
 * dual-approval shape (app/Services/TrustAccessPolicyService.php,
 * assertDistinctApprovers()) rather than inventing a new pattern,
 * because architecture.md §4 requires the identical dual-approval
 * discipline for financial-tier connect/disconnect/credential-rotation/
 * conflict-resolution actions:
 *   - Connect/initiate MAY BE REQUESTED by FirmOwner, Attorney, or
 *     BillingStaff, but only APPROVED by FirmOwner or Attorney,
 *     requiring a distinct second approver (symmetric with trust
 *     accounting's request/approve split).
 *   - Disconnect / credential rotation: same dual-approval requirement
 *     (architecture.md §4: "Same (symmetric, not weaker)").
 *   - Resolve conflicts on a monetary/trust field: dual-approval, same
 *     as disconnect.
 *   - View health/activity: FirmOwner, Attorney, BillingStaff ONLY —
 *     narrower than the non-financial tier's view ceiling (which also
 *     includes Paralegal/LegalAssistant).
 *   - View usage/billing impact: FirmOwner, BillingStaff — identical to
 *     the non-financial tier; see
 *     IntegrationAccessPolicyService::assertCanViewUsage(), not
 *     duplicated here.
 *
 * Role-tier ceilings may only be NARROWED by policy, never widened by
 * entitlement (frozen rule) — Paralegal, LegalAssistant, and
 * Receptionist NEVER receive any financial-tier integration
 * permission, full stop, regardless of any future entitlement grant.
 */
class FinancialIntegrationAccessPolicyService
{
    private const REQUESTER_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    private const APPROVER_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    public function canRequest(FirmUserRole $role): bool
    {
        return in_array($role, self::REQUESTER_ROLES, true);
    }

    public function canApprove(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function assertCanRequest(FirmUser $actor): void
    {
        if (! $this->canRequest($actor->role)) {
            throw new RuntimeException(
                'Only FirmOwner, Attorney, or BillingStaff may request a financial-tier integration action.'
            );
        }
    }

    public function assertCanApprove(FirmUser $actor): void
    {
        if (! $this->canApprove($actor->role)) {
            throw new RuntimeException(
                'Only FirmOwner or Attorney may approve a financial-tier integration action. '.
                'BillingStaff may request but not approve.'
            );
        }
    }

    public function assertCanView(FirmUser $actor): void
    {
        if (! $this->canView($actor->role)) {
            throw new RuntimeException(
                'Only FirmOwner, Attorney, or BillingStaff may view a financial-tier integration connection.'
            );
        }
    }

    /**
     * Connect, disconnect, credential rotation, and monetary conflict
     * resolution on a financial-tier connection all require two
     * DIFFERENT approvers, both from {FirmOwner, Attorney} — mirrors
     * TrustAccessPolicyService::assertDistinctApprovers() exactly.
     */
    public function assertDistinctApprovers(FirmUser $first, FirmUser $second): void
    {
        $this->assertCanApprove($first);
        $this->assertCanApprove($second);

        if ($first->id === $second->id) {
            throw new RuntimeException(
                'The second approver must be a different firm user than the first approver.'
            );
        }
    }
}
