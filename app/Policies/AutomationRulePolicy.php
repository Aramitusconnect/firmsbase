<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Automation\AutomationAccessPolicyService;

/**
 * AutomationRulePolicy — Event-Driven Automation Engine, item 15/17.
 * Mirrors PaymentPolicy's shape, gating the Firm UI's Automation Rules
 * resource with the exact same AutomationAccessPolicyService::canManageRules()
 * ceiling AutomationRuleService itself enforces at the service layer.
 *
 * This closes a real gap AutomationRuleResource's own ToggleColumn
 * on `enabled` would otherwise leave open: per that column's own
 * docblock, it writes directly via Eloquent (Filament's built-in
 * behavior) rather than through AutomationRuleService::setEnabled(),
 * so it never reaches assertAuthorized() at all. Gating viewAny() here
 * means an unauthorized role never reaches the list table to begin
 * with — the only way to structurally close that path, since a second
 * service-layer check would never be consulted by a raw Eloquent
 * write regardless.
 */
class AutomationRulePolicy
{
    public function __construct(
        private readonly AutomationAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorized($user);
    }

    public function view(User $user, AutomationRule $rule): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $rule);
    }

    public function create(User $user): bool
    {
        return $this->authorized($user);
    }

    public function update(User $user, AutomationRule $rule): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $rule);
    }

    private function authorized(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageRules($firmUser->role);
    }

    private function belongsToActorsFirm(User $user, AutomationRule $rule): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && (int) $firmUser->firm_id === (int) $rule->firm_id;
    }
}
