<?php

namespace Database\Factories;

use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    /**
     * Section 39A-3K — context-hold pattern (matching every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare CalendarEvent::factory()
     * ->create() works correctly even called from outside any already-
     * active tenant context. Deliberately does not clear context
     * afterward.
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
     * A bare CalendarEvent::factory()->create() has no matter_id and no
     * subject by default (both null), so there is no nested
     * tenant-owned parent for firm_id to disagree with — this default
     * path is safe as-is. Cross-firm mismatches are only possible when
     * a caller attaches a matter or subject; forMatter()/forSubject()
     * below are the only supported ways to do that, and both derive
     * firm_id FROM the parent rather than trusting whatever firm_id
     * definition() happened to resolve.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'event_type' => CalendarEventType::Standalone,
            'subject_type' => null,
            'subject_id' => null,
            'title' => $this->faker->sentence(3),
            'starts_at' => now()->addDays(2),
            'ends_at' => null,
            'all_day' => false,
            'created_by' => null,
        ];
    }

    /**
     * Ties the calendar event's firm_id to the given matter's own
     * firm_id (mirrors MatterFactory/PaymentFactory's forMatter()-style
     * states) — a bare CalendarEvent::factory()->forMatter($matter)
     * must never produce an event whose firm disagrees with the
     * matter's own firm.
     */
    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
        ]);
    }

    /**
     * Ties the calendar event's firm_id to the given subject's own
     * firm_id. Every production subject type (Deadline, Task) is
     * tenant-owned (BelongsToTenant), so deriving firm_id FROM the
     * subject here — rather than leaving definition()'s independently
     * resolved random Firm::factory() value in place — is deliberate:
     * mirrors the exact root-cause fix already applied to
     * MatterFactory/PaymentFactory/ConsultationFactory in prior FORCE
     * batches. Takes the real model instance (not a bare
     * subject_type/subject_id pair) specifically so firm_id can be read
     * off of it.
     */
    public function forSubject(Model $subject, CalendarEventType $type): static
    {
        return $this->state(fn () => [
            'firm_id' => $subject->firm_id,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'event_type' => $type,
        ]);
    }
}
