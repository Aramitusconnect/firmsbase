<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationProviderWebhookSubscription>
 *
 * integration_provider_webhook_subscriptions has permanent FORCE ROW
 * LEVEL SECURITY (see the paired
 * 2026_09_22_160002_prepare_row_level_security_and_force_rls_on_integration_provider_webhook_subscriptions_table
 * migration), so every INSERT (test or app) must run under the row's
 * own app.current_firm_id context. Mirrors
 * IntegrationSyncCursorFactory's context-hold convention exactly.
 */
class IntegrationProviderWebhookSubscriptionFactory extends Factory
{
    protected $model = IntegrationProviderWebhookSubscription::class;

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
     * Same lazy-memoization fix IntegrationSyncCursorFactory's own
     * docblock explains: nothing is created unless at least one of
     * firm_id/firm_integration_id survives, unoverridden, to the final
     * row.
     */
    private ?FirmIntegration $lazyFirmIntegration = null;

    public function definition(): array
    {
        $this->lazyFirmIntegration = null;

        return [
            'firm_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->firm_id;
            },
            'firm_integration_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->id;
            },
            'provider_key' => 'microsoft365',
            'resource_type' => 'message',
            'provider_resource' => "me/mailFolders('Inbox')/messages",
            'provider_change_type' => 'created,updated,deleted',
            'provider_subscription_id' => (string) Str::uuid(),
            'expires_at' => now()->addHours(70),
            'status' => ProviderWebhookSubscriptionStatus::Active->value,
            'last_renewed_at' => null,
            'last_renewal_error' => null,
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

    public function renewalFailed(): static
    {
        return $this->state(fn () => [
            'status' => ProviderWebhookSubscriptionStatus::RenewalFailed->value,
            'last_renewal_error' => 'provider_rejected',
        ]);
    }

    public function expiringWithin(\DateInterval|string $interval): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->add($interval),
        ]);
    }
}
