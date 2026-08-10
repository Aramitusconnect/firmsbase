<?php

namespace App\Services\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\TaskWorkCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TaskCategoryRoleExpectation;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use App\Services\TenantContextService;

/**
 * StaffingPolicyService — Leverage Ratio Optimizer, item 8. The sole
 * writer of task_category_role_expectations. Conservative by
 * construction: a task category with no row here has no Firm opinion
 * at all — TaskRoleMismatch simply cannot fire for it (see
 * LeverageRecommendationService). Reuses
 * MatterBudgetAccessPolicyService's own canManageTemplates() role
 * ceiling rather than inventing a second "who configures firm
 * policy" gate — this IS a firm-wide configuration object, the exact
 * same class of decision.
 */
class StaffingPolicyService
{
    public function __construct(private readonly MatterBudgetAccessPolicyService $accessPolicy) {}

    /**
     * @param  array<int, FirmUserRole>  $recommendedRoles
     */
    public function setExpectation(
        Firm $firm,
        FirmUser $actor,
        TaskWorkCategory $category,
        array $recommendedRoles,
        ?string $notes = null,
    ): TaskCategoryRoleExpectation {
        $this->assertAuthorized($actor);
        $this->validateRoles($recommendedRoles);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $actor, $category, $recommendedRoles, $notes) {
            // The lookup must run inside this same context — under
            // FORCE RLS an unscoped read returns zero rows even when
            // one already exists (same gotcha MatterBudgetService's
            // own docblock documents).
            $existing = TaskCategoryRoleExpectation::query()
                ->where('firm_id', $firm->id)
                ->where('task_category', $category->value)
                ->first();

            $roleValues = array_map(fn (FirmUserRole $r) => $r->value, $recommendedRoles);

            if ($existing === null) {
                return TaskCategoryRoleExpectation::create([
                    'firm_id' => $firm->id,
                    'task_category' => $category->value,
                    'recommended_roles_json' => $roleValues,
                    'notes' => $notes,
                    'created_by_firm_user_id' => $actor->id,
                    'updated_by_firm_user_id' => $actor->id,
                ]);
            }

            $existing->update([
                'recommended_roles_json' => $roleValues,
                'notes' => $notes,
                'updated_by_firm_user_id' => $actor->id,
            ]);

            return $existing->fresh();
        });
    }

    public function remove(Firm $firm, TaskCategoryRoleExpectation $expectation, FirmUser $actor): void
    {
        $this->assertAuthorized($actor);
        $this->assertBelongsToFirm($firm, $expectation, $actor);

        (new TenantContextService)->runWithFirmContext($firm, fn () => $expectation->delete());
    }

    /**
     * @return array<int, FirmUserRole>|null null when the Firm has no
     *                                       configured opinion for
     *                                       this category at all
     */
    public function recommendedRolesFor(Firm $firm, TaskWorkCategory $category): ?array
    {
        $expectation = TaskCategoryRoleExpectation::query()
            ->where('firm_id', $firm->id)
            ->where('task_category', $category->value)
            ->first();

        if ($expectation === null) {
            return null;
        }

        return array_map(fn (string $r) => FirmUserRole::from($r), $expectation->recommended_roles_json);
    }

    private function assertAuthorized(FirmUser $actor): void
    {
        if (! $this->accessPolicy->canManageTemplates($actor->role)) {
            throw new \RuntimeException('This user is not authorized to configure staffing policy.');
        }
    }

    private function assertBelongsToFirm(Firm $firm, TaskCategoryRoleExpectation $expectation, FirmUser $actor): void
    {
        if ((int) $expectation->firm_id !== (int) $firm->id || (int) $actor->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This staffing policy does not belong to this firm.');
        }
    }

    /**
     * @param  array<int, FirmUserRole>  $roles
     */
    private function validateRoles(array $roles): void
    {
        if (empty($roles)) {
            throw new \InvalidArgumentException('A staffing policy requires at least one recommended role.');
        }

        foreach ($roles as $role) {
            if (! $role instanceof FirmUserRole) {
                throw new \InvalidArgumentException('Every recommended role must be a real FirmUserRole.');
            }
        }
    }
}
