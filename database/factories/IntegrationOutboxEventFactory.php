<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationOutboxEvent>
 *
 * integration_outbox_events has permanent FORCE ROW LEVEL SECURITY
 * (see database/migrations/2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. Mirrors FirmIntegrationFactory's
 * context-hold convention exactly.
 */
class IntegrationOutboxEventFactory extends Factory
{
    protected $model = IntegrationOutboxEvent::class;

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
        // Section 39A-3L test-isolation fix: firm_id/firm_integration_id
        // used to be built via unconditional Firm::factory()->create() /
        // FirmIntegration::factory()->forFirm($firm)->create() calls
        // executed as plain PHP statements — real, committed side effects
        // that ran even when forFirmIntegration() below immediately
        // overrides both keys with a caller-supplied connection. Lazy
        // factory-relationship values (like FirmIntegrationFactory's own
        // 'firm_id' => Firm::factory()) are only resolved when the key
        // survives to the final merged attribute array, i.e. never when a
        // later state() overrides it — the fix here mirrors that
        // established, correct pattern instead of eagerly wasting a real
        // Firm + FirmIntegration on every forFirmIntegration()-scoped
        // create() (this factory's normal, intended usage everywhere in
        // this codebase).
        return [
            'firm_id' => Firm::factory(),
            'firm_integration_id' => fn (array $attributes) => FirmIntegration::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id']))
                ->create()
                ->id,
            'domain_event_id' => (string) Str::uuid(),
            'event_type' => 'token_refresh_retry',
            'resource_type' => null,
            'resource_id' => null,
            'payload_json' => ['resource_type' => null, 'resource_id' => null, 'fields' => []],
            'payload_hash' => null,
            'status' => OutboxEventStatus::Pending->value,
            'attempts' => 0,
            'max_attempts' => 10,
            'next_attempt_at' => now(),
        ];
    }

    /**
     * Overrides firm_id AND firm_integration_id together — never
     * independently.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(fn () => [
            'firm_id' => $firmIntegration->firm_id,
            'firm_integration_id' => $firmIntegration->id,
        ]);
    }

    public function withoutFirmIntegration(): static
    {
        return $this->state(fn () => ['firm_integration_id' => null]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => OutboxEventStatus::Processing->value,
            'lock_token' => (string) Str::uuid(),
            'locked_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => OutboxEventStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function deadLettered(): static
    {
        return $this->state(fn () => [
            'status' => OutboxEventStatus::DeadLettered->value,
            'dead_lettered_at' => now(),
            'attempts' => 10,
            'last_error' => 'simulated_exhausted_retries',
        ]);
    }
}
