<?php

namespace Database\Factories;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\PlatformAdmin;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<LegalHold>
 */
class LegalHoldFactory extends Factory
{
    protected $model = LegalHold::class;

    /**
     * legal_holds has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_28_960001_prepare_row_level_security_
     * and_force_rls_on_legal_holds_table.php), so every INSERT (test or
     * app) must run under the row's own app.current_firm_id context.
     * See MatterFactory::create()'s docblock for the full rationale,
     * including why setDatabaseTenantContextForFirmId() is used instead
     * of setFirmContext()/runWithFirmContext() and why the setting is
     * deliberately left active rather than cleared.
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
        $admin = PlatformAdmin::factory()->create();

        return [
            'firm_id' => Firm::factory(),
            'scope_type' => LegalHoldScope::Firm,
            'client_id' => null,
            'matter_id' => null,
            'document_id' => null,
            'reason' => 'Pending litigation.',
            'status' => LegalHoldStatus::Active,
            'placed_by_type' => PlatformAdmin::class,
            'placed_by_id' => $admin->id,
            'placed_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => LegalHoldStatus::Released,
            'released_by_type' => PlatformAdmin::class,
            'released_by_id' => PlatformAdmin::factory(),
            'released_at' => now(),
            'release_reason' => 'Litigation concluded.',
        ]);
    }
}
