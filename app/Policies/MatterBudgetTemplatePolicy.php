<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MatterBudgetTemplate;
use App\Models\User;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;

/**
 * MatterBudgetTemplatePolicy — Predictive Matter Budget Alerts, item
 * 18/21. Mirrors AutomationRulePolicy's shape exactly, gating the Firm
 * UI's Budget Templates resource with the same
 * MatterBudgetAccessPolicyService::canManageTemplates() ceiling
 * MatterBudgetTemplateService itself enforces at the service layer —
 * template management is firm-wide financial configuration, not
 * something every role should even be able to view.
 */
class MatterBudgetTemplatePolicy
{
    public function __construct(
        private readonly MatterBudgetAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorized($user);
    }

    public function view(User $user, MatterBudgetTemplate $template): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $template);
    }

    public function create(User $user): bool
    {
        return $this->authorized($user);
    }

    public function update(User $user, MatterBudgetTemplate $template): bool
    {
        return $this->authorized($user) && $this->belongsToActorsFirm($user, $template);
    }

    private function authorized(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageTemplates($firmUser->role);
    }

    private function belongsToActorsFirm(User $user, MatterBudgetTemplate $template): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && (int) $firmUser->firm_id === (int) $template->firm_id;
    }
}
