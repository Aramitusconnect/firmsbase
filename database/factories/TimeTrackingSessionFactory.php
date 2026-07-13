<?php

namespace Database\Factories;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Firm;
use App\Models\TimeTrackingSession;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<TimeTrackingSession>
 */
class TimeTrackingSessionFactory extends Factory
{
    protected $model = TimeTrackingSession::class;

    /**
     * Section 39A-3L, Checkpoint 20 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * TimeTrackingSession::factory()->create() works correctly even
     * called from outside any already-active tenant context.
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
            'matter_id' => null,
            'client_id' => null,
            'status' => TimeTrackingSessionStatus::Active,
            'started_at' => now(),
            'accumulated_seconds' => 0,
            'last_resumed_at' => now(),
            'ended_at' => null,
            'total_seconds' => null,
            'is_billable' => true,
            'description' => 'Drafting motion',
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function stopped(int $totalSeconds): static
    {
        return $this->state(fn () => [
            'status' => TimeTrackingSessionStatus::Stopped,
            'accumulated_seconds' => $totalSeconds,
            'total_seconds' => $totalSeconds,
            'last_resumed_at' => null,
            'ended_at' => now(),
        ]);
    }
}
