<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;

/**
 * TrustAccessPolicyService — the trust-specific role gate (approved
 * correction #6):
 *   - Requests (deposits/transfers/refunds) may be created by
 *     FirmOwner, Attorney, or BillingStaff.
 *   - Approvals may be performed only by FirmOwner or Attorney —
 *     BillingStaff may prepare/request trust actions but never
 *     approves them.
 *   - High-risk trust adjustments require two DIFFERENT approvers,
 *     both from {FirmOwner, Attorney}.
 */
class TrustAccessPolicyService
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

    public function canRequest(FirmUserRole $role): bool
    {
        return in_array($role, self::REQUESTER_ROLES, true);
    }

    public function canApprove(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    public function assertCanRequest(FirmUser $firmUser): void
    {
        if (! $this->canRequest($firmUser->role)) {
            throw new \RuntimeException('Only FirmOwner, Attorney, or BillingStaff may request a trust action.');
        }
    }

    public function assertCanApprove(FirmUser $firmUser): void
    {
        if (! $this->canApprove($firmUser->role)) {
            throw new \RuntimeException('Only FirmOwner or Attorney may approve a trust action. BillingStaff may request but not approve.');
        }
    }

    /**
     * High-risk adjustments require two different approvers, both
     * eligible to approve.
     */
    public function assertDistinctApprovers(FirmUser $first, FirmUser $second): void
    {
        $this->assertCanApprove($first);
        $this->assertCanApprove($second);

        if ($first->id === $second->id) {
            throw new \RuntimeException('The second approver must be a different firm user than the first approver.');
        }
    }

    /**
     * Ordinary trust deposit/transfer/refund approvals also require
     * maker/checker separation, not just role eligibility: without
     * this, a single FirmOwner or Attorney could both request and
     * approve their own trust action unilaterally, while the same
     * principle was already enforced for high-risk adjustments via
     * assertDistinctApprovers(). This closes that gap for the ordinary
     * request/approve flows.
     */
    public function assertApproverIsNotRequester(FirmUser $approver, int $requestedByFirmUserId): void
    {
        if ($approver->id === $requestedByFirmUserId) {
            throw new \RuntimeException('The approver must be a different firm user than whoever requested this trust action.');
        }
    }
}
