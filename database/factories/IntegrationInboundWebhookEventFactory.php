<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\WebhookInboundEventStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationWebhookReceipt;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationInboundWebhookEvent>
 *
 * integration_inbound_webhook_events has permanent FORCE ROW LEVEL
 * SECURITY (see
 * database/migrations/2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. This create() override mirrors
 * IntegrationOAuthStateFactory/FirmIntegrationFactory's exact
 * context-hold convention: groups resolved models by firm_id and
 * activates the matching PostgreSQL session context per group before
 * inserting.
 *
 * `receipt_id` is resolved from a REAL
 * IntegrationWebhookReceiptFactory-created row (never a bare fake
 * integer) — that table has no RLS of its own, so no context
 * coordination is needed for it specifically, only for this table's
 * own insert.
 */
class IntegrationInboundWebhookEventFactory extends Factory
{
    protected $model = IntegrationInboundWebhookEvent::class;

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
     * FirmIntegration::factory()->create() and
     * IntegrationWebhookReceipt::factory()->create() as plain PHP
     * statements at the top of definition() — real, committed rows
     * every single time, even when forFirmIntegration()/forReceipt()
     * below immediately override the keys derived from them with
     * caller-supplied values. Fixed by memoizing each behind its own
     * lazy closures so nothing is created unless at least one of its
     * derived keys survives, unoverridden, to the final row.
     */
    private ?FirmIntegration $lazyFirmIntegration = null;

    private ?IntegrationWebhookReceipt $lazyReceipt = null;

    public function definition(): array
    {
        $this->lazyFirmIntegration = null;
        $this->lazyReceipt = null;

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
            'receipt_id' => function () {
                $this->lazyReceipt ??= IntegrationWebhookReceipt::factory()->create();

                return $this->lazyReceipt->id;
            },
            'provider_key' => 'test',
            'provider_event_id' => (string) Str::uuid(),
            'receipt_body_hash' => function () {
                $this->lazyReceipt ??= IntegrationWebhookReceipt::factory()->create();

                return $this->lazyReceipt->body_hash;
            },
            'event_type' => 'test.resource.created',
            'payload_reference_json' => [],
            'payload_hash' => null,
            'status' => WebhookInboundEventStatus::Verified->value,
            'lock_token' => null,
            'locked_at' => null,
            'processing_attempts' => 0,
            'failure_code' => null,
            'failure_detail' => null,
            'triggering_sync_run_id' => null,
            'received_at' => $now,
            'started_processing_at' => null,
            'processed_at' => null,
            'terminal_at' => null,
            'retention_deadline' => $now->copy()->addDays(400),
        ];
    }

    /**
     * Overrides firm_id/firm_integration_id together (never
     * independently settable) — mirrors
     * FirmIntegrationFactory::forFirm()/IntegrationOAuthStateFactory::forFirmIntegration()'s
     * identical discipline.
     */
    public function forFirmIntegration(FirmIntegration $firmIntegration): static
    {
        return $this->state(fn () => [
            'firm_id' => $firmIntegration->firm_id,
            'firm_integration_id' => $firmIntegration->id,
        ]);
    }

    public function forReceipt(IntegrationWebhookReceipt $receipt): static
    {
        return $this->state(fn () => [
            'receipt_id' => $receipt->id,
            'receipt_body_hash' => $receipt->body_hash,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => WebhookInboundEventStatus::Processed->value,
            'processed_at' => now(),
            'terminal_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => WebhookInboundEventStatus::Failed->value,
            'failure_code' => 'processing_failed',
            'terminal_at' => now(),
        ]);
    }
}
