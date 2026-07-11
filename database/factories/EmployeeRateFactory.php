<?php

namespace Database\Factories;

use App\Models\EmployeeRate;
use App\Models\Firm;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<EmployeeRate>
 */
class EmployeeRateFactory extends Factory
{
    protected $model = EmployeeRate::class;

    /**
     * Section 39A-3K — context-hold pattern (matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare EmployeeRate::factory()
     * ->create() works correctly even called from outside any already-
     * active tenant context. Deliberately does not clear context
     * afterward. user_id references the non-tenant users table (an
     * "employee" is a platform User acting inside this firm, per the
     * migration's own doc comment) — there is no tenant-owned parent
     * whose firm_id the factory could mismatch against, so no
     * ownership-consistency fix is needed here. Whether the referenced
     * user must ALSO hold a firm_users membership row for this firm is
     * a separate, pre-existing business-authorization question (see
     * EmployeeRateService's own docblock) that this batch's FORCE
     * activation does not change the safety of one way or the other —
     * see the batch report for the full analysis.
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
            'user_id' => User::factory(),
            'billing_rate_cents' => 25000,
            'cost_rate_cents' => 12000,
            'currency' => 'usd',
            'effective_from' => now()->subMonth(),
            'effective_to' => null,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function closed(\DateTimeInterface $effectiveTo): static
    {
        return $this->state(fn () => ['effective_to' => $effectiveTo]);
    }
}
