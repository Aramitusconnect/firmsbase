<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\NotificationEventStatus;
use App\Models\Firm;
use App\Models\NotificationEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationEvent>
 */
class NotificationEventFactory extends Factory
{
    protected $model = NotificationEvent::class;

    /**
     * Section 39A-3L, Checkpoint 24 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * NotificationEvent::factory()->create() works correctly even
     * called from outside any already-active tenant context.
     * notification_template_id/client_id/matter_id are all nullable
     * and already default to null in definition() below, so — unlike
     * PaymentPlanEventFactory/PaymentPlanFactory — no "one authoritative
     * firm" fix was needed here: a bare call cannot produce a
     * cross-firm mismatch, since it references no other tenant-owned
     * row at all. This override is added regardless, matching this
     * mission's universal safety-net convention for every FORCE-RLS
     * factory.
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
            'notification_template_id' => null,
            'client_id' => null,
            'matter_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'channel' => ConsentChannel::Email,
            'recipient' => $this->faker->safeEmail(),
            'status' => NotificationEventStatus::Attempted,
            'reason' => null,
            'subject_type' => null,
            'subject_id' => null,
        ];
    }

    public function blocked(string $reason): static
    {
        return $this->state(fn () => ['status' => NotificationEventStatus::Blocked, 'reason' => $reason]);
    }
}
