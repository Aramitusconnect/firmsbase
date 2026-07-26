<?php

namespace Database\Factories;

use App\Enums\AiToolActionStatus;
use App\Models\AiToolAction;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AiToolAction>
 */
class AiToolActionFactory extends Factory
{
    protected $model = AiToolAction::class;

    /**
     * ai_tool_actions has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950014_prepare_row_level_security_
     * and_force_rls_on_ai_tool_actions_table.php), so every INSERT (test
     * or app) must run under the row's own app.current_firm_id context.
     * See MatterExpenseFactory::create()'s docblock for the full
     * rationale, including why setDatabaseTenantContextForFirmId() is
     * used instead of setFirmContext()/runWithFirmContext() and why the
     * setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The ai_tool_actions row and its nested ai_usage_event are always
     * tied to the SAME firm.
     *
     * Audit fix (eager-factory-side-effects audit): definition() used
     * to call Firm::factory()->create() as a plain PHP statement at the
     * top of this method, then unconditionally provision a real
     * AiUsageEvent for it via runWithFirmContext() — real, committed
     * side effects every single time, even when forFirm() below
     * immediately overrides firm_id/ai_usage_event_id with a
     * caller-supplied firm. Laravel cannot skip a side effect that
     * already happened while building the array. Fixed by making
     * firm_id Laravel's own lazy factory-relationship form and deriving
     * ai_usage_event_id from the already-resolved firm_id via a lazy
     * closure (mirrors IntegrationOAuthStateFactory's
     * Firm::query()->findOrFail($attributes['firm_id']) convention) —
     * nothing is created unless it survives, unoverridden, to the final
     * row. ai_usage_event_id still requires the real Firm model (not
     * just its id) to route through runWithFirmContext()/forFirm(),
     * since ai_usage_events also has FORCE ROW LEVEL SECURITY with no
     * context-hold create() override of its own.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'ai_usage_event_id' => function (array $attributes) {
                $firm = Firm::query()->findOrFail($attributes['firm_id']);

                return (new TenantContextService)->runWithFirmContext(
                    $firm,
                    fn () => AiUsageEvent::factory()->forFirm($firm)->create(),
                )->id;
            },
            'tool_name' => 'draft_summary_tool',
            'input_snapshot_json' => ['note' => 'fixture input'],
            'output_snapshot_json' => ['note' => 'fixture output'],
            'was_constrained' => false,
            'status' => AiToolActionStatus::Executed,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            // Eagerly created and wrapped in its own tenant context
            // (not passed as a lazy Factory instance) because
            // ai_usage_events also has FORCE ROW LEVEL SECURITY with no
            // context-hold create() override of its own (by design —
            // see AiUsageEventFactory) — Laravel resolves a nested
            // Factory value during make(), before this factory's own
            // create() override establishes context below, so a lazy
            // reference here would insert with no context active.
            'ai_usage_event_id' => (new TenantContextService)->runWithFirmContext(
                $firm,
                fn () => AiUsageEvent::factory()->forFirm($firm)->create(),
            )->id,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'was_constrained' => true,
            'status' => AiToolActionStatus::Blocked,
        ]);
    }
}
