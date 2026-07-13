<?php

namespace Database\Factories;

use App\Models\Firm;
use App\Models\SecurityEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    protected $model = SecurityEvent::class;

    public function definition(): array
    {
        return [
            // Nullable by default — platform-level events are legitimate.
            'firm_id' => null,
            'actor_type' => 'User',
            'actor_id' => null,
            'event_type' => 'login_succeeded',
            'category' => 'authentication',
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'metadata' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    /**
     * Section 39A-3L Phase B6 — context-hold create() override,
     * transplanted from BackupRestoreTestFactory's own template (see
     * that factory's docblock for the full rationale): groups
     * bare-created models by resolved firm_id, clearing DB-session
     * tenant context before store() for the null group and setting it
     * before store() for the non-null group. A factory create() call is
     * always the outermost tenant-context operation for the row(s) it
     * is producing, so a direct, unconditional
     * clearDatabaseTenantContext()/setDatabaseTenantContextForFirmId()
     * pair is correct here — not runWithFirmContext()'s/
     * runWithoutFirmContext()'s save/restore wrapper, which exists to
     * protect an already-active OUTER context this call site never has.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = app(TenantContextService::class);

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $firmId = $group->first()->firm_id;

            if ($firmId === null) {
                $service->clearDatabaseTenantContext();
                $this->store($group);

                return;
            }

            $service->setDatabaseTenantContextForFirmId($firmId);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }
}
