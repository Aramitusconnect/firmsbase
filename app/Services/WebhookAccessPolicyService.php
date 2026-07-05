<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\ValueObjects\WebhookAccessDecision;

/**
 * WebhookAccessPolicyService — the trust-mirrored role gate (approved
 * correction #10): only FirmOwner and Attorney may create, rotate,
 * disable, or replay a firm's webhook subscriptions. BillingStaff may
 * not manage webhooks by default — unlike Phase 13's trust workflows,
 * there is no "requester" role separate from "approver" here; webhook
 * management is a single-tier action.
 */
class WebhookAccessPolicyService
{
    private const MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function canManage(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function evaluate(FirmUser $actor): WebhookAccessDecision
    {
        if (! $this->canManage($actor->role)) {
            return WebhookAccessDecision::deny('Only FirmOwner or Attorney may manage webhook subscriptions. BillingStaff may not.');
        }

        return WebhookAccessDecision::allow();
    }

    public function assertCanManage(FirmUser $actor): void
    {
        $decision = $this->evaluate($actor);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason);
        }
    }
}
