<?php

namespace Database\Factories;

use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Firm;
use App\Models\SeatAllocation;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SeatAllocation>
 */
class SeatAllocationFactory extends Factory
{
    protected $model = SeatAllocation::class;

    /**
     * Section 39A-3L, Checkpoint 9 — context-hold pattern (matching
     * TemplateUpgradeLogFactory/InstalledTemplatePackFactory from prior
     * checkpoints in this arc): groups resolved models by firm_id and
     * activates the matching PostgreSQL session context per group
     * before inserting, so a bare SeatAllocation::factory()->create()
     * works correctly even called from outside any already-active
     * tenant context. No cross-firm mismatch exists in definition()
     * today (seat_pool_id defaults to null; seat_pools itself carries
     * no firm_id/RLS boundary), so this is purely future-proofing, not
     * a bug fix.
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
            'seat_pool_id' => null,
            'seat_class' => SeatClass::Attorney,
            'seats_allocated' => 5,
            'status' => SeatAllocationStatus::Active,
            'created_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function seatClass(SeatClass $seatClass): static
    {
        return $this->state(fn () => ['seat_class' => $seatClass]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => SeatAllocationStatus::Revoked]);
    }
}
