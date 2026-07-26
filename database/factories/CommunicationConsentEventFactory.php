<?php

namespace Database\Factories;

use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<CommunicationConsentEvent>
 */
class CommunicationConsentEventFactory extends Factory
{
    protected $model = CommunicationConsentEvent::class;

    /**
     * Section 39A-3L, Checkpoint 12 — context-hold pattern (matching
     * CommunicationConsentFactory from Checkpoint 11 and every prior
     * FORCE-RLS factory since 39A-3A): groups resolved models by
     * firm_id and activates the matching PostgreSQL session context per
     * group before inserting, so a bare
     * CommunicationConsentEvent::factory()->create() works correctly
     * even called from outside any already-active tenant context.
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
     * communication_consent_id is derived from the SAME
     * CommunicationConsent that firm_id comes from.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * CommunicationConsent::factory()->create() as a plain PHP statement
     * at the top of definition() — a real, committed CommunicationConsent
     * every single time, even when forConsent() below immediately
     * overrides both keys with a caller-supplied consent. Fixed by
     * memoizing the consent behind lazy closures so nothing is created
     * unless it survives, unoverridden, to the final row.
     */
    private ?CommunicationConsent $lazyConsent = null;

    public function definition(): array
    {
        $this->lazyConsent = null;

        return [
            'communication_consent_id' => function () {
                $this->lazyConsent ??= CommunicationConsent::factory()->create();

                return $this->lazyConsent->id;
            },
            'firm_id' => function () {
                $this->lazyConsent ??= CommunicationConsent::factory()->create();

                return $this->lazyConsent->firm_id;
            },
            'action' => 'captured',
            'previous_status' => null,
            'new_status' => 'granted',
            'consent_text_version' => 'v1',
            'actor_user_id' => null,
            'source' => 'web_form',
            'metadata_json' => [],
        ];
    }

    public function forConsent(CommunicationConsent $consent): static
    {
        return $this->state(fn () => [
            'communication_consent_id' => $consent->id,
            'firm_id' => $consent->firm_id,
        ]);
    }
}
