<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\UsageOperationType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationUsageRecord>
 *
 * integration_usage_records has permanent FORCE ROW LEVEL SECURITY
 * (see the companion
 * 2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php
 * migration), so every INSERT (test or app) must run under the row's
 * own app.current_firm_id context. This create() override mirrors
 * IntegrationInboundWebhookEventFactory/FirmIntegrationFactory's exact
 * context-hold convention: groups resolved models by firm_id and
 * activates the matching PostgreSQL session context per group before
 * inserting.
 */
class IntegrationUsageRecordFactory extends Factory
{
    protected $model = IntegrationUsageRecord::class;

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
     * Audit fix (eager-factory-side-effects audit): this used to call
     * FirmIntegration::factory()->create() as a plain PHP statement at
     * the top of definition() — a real, committed FirmIntegration (+ its
     * own nested Firm) every single time, even when forFirmIntegration()
     * below immediately overrides both firm_id and firm_integration_id
     * with a caller-supplied connection. Fixed by memoizing the
     * connection behind lazy closures so nothing is created unless it
     * survives, unoverridden, to the final row.
     */
    private ?FirmIntegration $lazyFirmIntegration = null;

    public function definition(): array
    {
        $this->lazyFirmIntegration = null;

        $now = now();

        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->firm_id;
            },
            'firm_integration_id' => function () {
                $this->lazyFirmIntegration ??= FirmIntegration::factory()->create();

                return $this->lazyFirmIntegration->id;
            },
            'provider_key' => 'test',
            'capability' => 'sync',
            'operation_type' => UsageOperationType::PullSync->value,
            'direction' => SyncDirection::Inbound->value,
            'resource_type' => null,
            'quantity' => 1,
            'unit' => 'item',
            'outcome' => 'success',
            'occurred_at' => $now,
            'correlation_id' => null,
            'sync_run_id' => null,
            'sync_item_id' => null,
            'inbound_webhook_event_id' => null,
            'outbox_event_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'metadata_json' => [],
            'retention_deadline' => null,
        ];
    }

    /**
     * Overrides firm_id/firm_integration_id together (never
     * independently settable) — mirrors
     * IntegrationInboundWebhookEventFactory::forFirmIntegration()'s
     * identical discipline.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(fn () => [
            'firm_id' => $firmIntegration->firm_id,
            'firm_integration_id' => $firmIntegration->id,
        ]);
    }
}
