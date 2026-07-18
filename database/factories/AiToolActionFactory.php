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

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The ai_tool_actions row and its nested ai_usage_event are always
     * tied to the SAME firm — one authoritative firm is generated up
     * front (rather than letting firm_id and ai_usage_event_id resolve
     * as two independent Firm::factory() calls), matching the
     * root-cause fix already applied to MatterExpenseFactory/
     * MatterFactory/InvoiceFactory/PaymentFactory. A bare ai_tool_actions
     * row whose ai_usage_event belongs to an unrelated firm is exactly
     * the transitive cross-firm mismatch documented as a known,
     * deliberately-deferred gap in this table's FORCE migration (no
     * composite FK/trigger enforces it at the database layer) — the
     * factory must not manufacture that invalid shape by default just
     * because RLS itself cannot catch it. matter_id is left null by
     * default (nullable, unrelated to this fix); forFirm() below does
     * not attempt to also tie an existing ai_usage_event_id to the new
     * firm — callers needing that must supply a consistent
     * ai_usage_event_id explicitly.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'matter_id' => null,
            'ai_usage_event_id' => AiUsageEvent::factory()->forFirm($firm),
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
            'ai_usage_event_id' => AiUsageEvent::factory()->forFirm($firm),
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
