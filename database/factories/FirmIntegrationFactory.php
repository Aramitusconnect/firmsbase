<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<FirmIntegration>
 *
 * firm_integrations has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. This create() override mirrors
 * EmailAccountFactory::create() exactly (see
 * database/factories/EmailAccountFactory.php): definition()'s
 * connected_by_firm_user_id is a lazy closure that reads the
 * already-resolved firm_id, so the two columns — and
 * FirmIntegration's own saving-listener compensating check (see
 * app/Integrations/Models/FirmIntegration.php) — can never disagree.
 *
 * `integration_provider_id` deliberately never hardcodes a real
 * provider id: it resolves the existing seeded `integration_providers`
 * row with code='test' (see
 * database/migrations/2026_09_01_010001_create_integration_providers_table.php)
 * and only falls back to a freshly factory-created IntegrationProvider
 * row (itself guaranteed synthetic — see
 * database/factories/IntegrationProviderFactory.php's own docblock) if
 * that seeded row is somehow absent.
 */
class FirmIntegrationFactory extends Factory
{
    protected $model = FirmIntegration::class;

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
            'integration_provider_id' => fn () => IntegrationProvider::query()->where('code', 'test')->first()?->id
                ?? IntegrationProvider::factory()->create()->id,
            'external_account_id' => fake()->boolean(70) ? fake()->unique()->uuid() : null,
            'display_label' => fake()->company().' Test Connection',
            'status' => ConnectionStatus::Active->value,
            'scopes_granted_json' => ['test.read', 'test.write'],
            // Resolved lazily so the created FirmUser belongs to the SAME
            // firm as this connection — firm_id above is already a real,
            // persisted id by the time this closure runs. Mirrors
            // EmailAccountFactory::definition()'s identical pattern.
            'connected_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_health_check_at' => null,
            'last_health_status' => null,
            'error_reason' => null,
            'webhook_routing_token' => Str::random(40),
        ];
    }

    /**
     * Overrides BOTH firm_id and connected_by_firm_user_id together
     * (rather than firm_id alone) so a caller can never end up with a
     * fixture where the connection's firm_id and its connecting
     * FirmUser's firm_id disagree — mirrors
     * EmailAccountFactory::forFirm() exactly.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'connected_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function forProvider(IntegrationProvider $provider): static
    {
        return $this->state(fn () => ['integration_provider_id' => $provider->id]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ConnectionStatus::Pending->value,
            'connected_at' => null,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn () => [
            'status' => ConnectionStatus::Disconnected->value,
            'disconnected_at' => now(),
        ]);
    }

    public function withoutExternalAccountId(): static
    {
        return $this->state(fn () => ['external_account_id' => null]);
    }
}
