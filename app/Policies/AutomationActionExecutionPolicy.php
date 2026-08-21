<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutomationActionExecution;
use App\Models\User;
use App\Services\Automation\AutomationAccessPolicyService;

/**
 * AutomationActionExecutionPolicy — Completion & Activation Program,
 * Mission 2, finding 2.3. AutomationActionExecutionResource (the
 * Automation "Activity Log", surfacing per-action execution status,
 * risk level, and `last_error` text) had no Filament ->canAccess()
 * override and no Laravel Policy existed for AutomationActionExecution,
 * so Filament's default (no policy → allowed) let ANY authenticated
 * firm user of any role view it, regardless of role.
 *
 * Mirrors AutomationRulePolicy's exact shape: the SAME
 * AutomationAccessPolicyService::canManageRules() ceiling
 * (FirmOwner/Attorney/BillingStaff) AutomationRuleResource already
 * enforces for the rules that produce these executions — viewing what
 * automation DID should not be less guarded than viewing/configuring
 * the rules that made it happen.
 */
class AutomationActionExecutionPolicy
{
    public function __construct(
        private readonly AutomationAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorized($user);
    }

    public function view(User $user, AutomationActionExecution $execution): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $execution);
    }

    private function authorized(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageRules($firmUser->role);
    }

    private function belongsToActorsFirm(User $user, AutomationActionExecution $execution): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && (int) $firmUser->firm_id === (int) $execution->firm_id;
    }
}
