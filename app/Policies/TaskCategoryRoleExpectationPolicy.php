<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaskCategoryRoleExpectation;
use App\Models\User;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;

/**
 * TaskCategoryRoleExpectationPolicy — Leverage Ratio Optimizer, item
 * 8/27. Mirrors MatterBudgetTemplatePolicy's shape exactly, gating the
 * Firm UI's Staffing Policies resource with the same
 * MatterBudgetAccessPolicyService::canManageTemplates() ceiling
 * StaffingPolicyService itself already enforces at the service layer
 * — this is firm-wide staffing configuration, the same class of
 * decision as budget template management.
 */
class TaskCategoryRoleExpectationPolicy
{
    public function __construct(
        private readonly MatterBudgetAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorized($user);
    }

    public function view(User $user, TaskCategoryRoleExpectation $expectation): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $expectation);
    }

    public function create(User $user): bool
    {
        return $this->authorized($user);
    }

    public function update(User $user, TaskCategoryRoleExpectation $expectation): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $expectation);
    }

    public function delete(User $user, TaskCategoryRoleExpectation $expectation): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $expectation);
    }

    private function authorized(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageTemplates($firmUser->role);
    }

    private function belongsToActorsFirm(User $user, TaskCategoryRoleExpectation $expectation): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && (int) $firmUser->firm_id === (int) $expectation->firm_id;
    }
}
