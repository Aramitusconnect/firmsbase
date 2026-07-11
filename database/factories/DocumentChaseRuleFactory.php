<?php

namespace Database\Factories;

use App\Enums\DocumentChaseRuleStatus;
use App\Models\DocumentChaseRule;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<DocumentChaseRule>
 */
class DocumentChaseRuleFactory extends Factory
{
    protected $model = DocumentChaseRule::class;

    /**
     * Section 39A-3K — context-hold pattern (matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare DocumentChaseRule::factory()
     * ->create() works correctly even called from outside any already-
     * active tenant context. Deliberately does not clear context
     * afterward. escalate_to_user_id/created_by reference the
     * non-tenant users table, not a tenant-owned parent, so there is no
     * nested-record ownership mismatch to fix here.
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

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'name' => 'Default reminder cadence',
            'status' => DocumentChaseRuleStatus::Active,
            'applies_to' => null,
            'reminder_offsets_days' => [7, 3, 1],
            'max_reminders' => 3,
            'escalate_after_days' => 14,
            'escalate_to_user_id' => null,
            'channel' => 'email',
            'created_by' => null,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => DocumentChaseRuleStatus::Paused]);
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
