<?php

namespace Database\Factories;

use App\Enums\DeadlineStatus;
use App\Models\Deadline;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Deadline>
 */
class DeadlineFactory extends Factory
{
    protected $model = Deadline::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'title' => $this->faker->sentence(3),
            'deadline_type' => 'filing_deadline',
            'due_at' => now()->addDays(30),
            'jurisdiction' => 'USCIS',
            'source' => 'court_order',
            'reminder_offsets_days' => [7, 3, 1],
            'status' => DeadlineStatus::Upcoming,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_by' => null,
        ];
    }

    public function missed(): static
    {
        return $this->state(fn () => ['status' => DeadlineStatus::Missed, 'due_at' => now()->subDays(2)]);
    }

    /**
     * Section 39A-3D — deadlines has FORCE ROW LEVEL SECURITY active,
     * so every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. Same pattern as ClientFactory
     * (Section 39A-3A), FirmUserFactory (Section 39A-3B), and
     * DocumentFactory (Section 39A-3C): reads the firm_id the factory
     * itself already resolved, sets the PostgreSQL session setting
     * only (never PHP-memory TenantContextResolver state, which
     * BelongsToTenant's global scope reads — leaving that active would
     * leak an implicit firm_id constraint into unrelated queries), and
     * deliberately leaves it set afterward for the common "create then
     * read" test pattern.
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
}
