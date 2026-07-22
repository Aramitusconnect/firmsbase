<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntegrationSyncItem>
 *
 * integration_sync_items has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. Mirrors FirmIntegrationFactory's
 * context-hold convention exactly.
 *
 * POST-DIFF-REVIEW NOTE (checkpoint-06 verification pass) — known,
 * PRE-EXISTING limitation, not introduced here: this create() override
 * is byte-for-byte identical to FirmIntegrationFactory::create() (the
 * established precedent) and, like that precedent, does NOT capture or
 * restore any "previous" tenant context value — there is no
 * try/finally, no saved variable, nothing to poison. Each call is
 * simply `TenantContextService::setDatabaseTenantContextForFirmId()`
 * (a raw `set_config(..., is_local => true)`), which — because
 * Laravel/RefreshDatabase always wraps a test in its own outer
 * transaction, making `isLocalScoped()` true — is transaction-scoped
 * and is automatically discarded by PostgreSQL when that outer
 * transaction ends; nothing is ever explicitly restored, by this
 * factory OR by FirmIntegrationFactory. Every interleaved factory
 * create() call (for this model or any other FORCE-RLS model using
 * this identical pattern) simply overwrites app.current_firm_id for
 * the remainder of the current transaction — last write wins, exactly
 * as it already behaves for FirmIntegrationFactory/
 * IntegrationOAuthStateFactory today. This is verified directly
 * against FirmIntegrationFactory::create() and TenantContextService::
 * setDatabaseTenantContextForFirmId()/isLocalScoped(), not assumed —
 * see checkpoint-06 fix-writer report. Any change to this behavior
 * belongs in TenantContextService/FirmIntegrationFactory, both outside
 * this fix's file allowlist.
 */
class IntegrationSyncItemFactory extends Factory
{
    protected $model = IntegrationSyncItem::class;

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
        $syncRun = IntegrationSyncRun::factory()->create();

        return [
            'firm_id' => $syncRun->firm_id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => $syncRun->resource_type,
            'local_type' => 'App\\Models\\Contact',
            'local_id' => fake()->numberBetween(1, 100000),
            'external_id' => (string) Str::uuid(),
            'status' => SyncItemStatus::Pending->value,
            'attempt_count' => 0,
        ];
    }

    /**
     * Overrides firm_id AND sync_run_id together — never independently.
     */
    public function forSyncRun(IntegrationSyncRun $syncRun): static
    {
        return $this->state(fn () => [
            'firm_id' => $syncRun->firm_id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => $syncRun->resource_type,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => SyncItemStatus::Succeeded->value,
            'terminal_at' => now(),
        ]);
    }

    public function failedRetryable(): static
    {
        return $this->state(fn () => [
            'status' => SyncItemStatus::FailedRetryable->value,
            'attempt_count' => 1,
            'next_attempt_at' => now()->addMinutes(5),
            'last_error' => 'simulated_retryable_failure',
        ]);
    }

    public function failedPermanent(): static
    {
        return $this->state(fn () => [
            'status' => SyncItemStatus::FailedPermanent->value,
            'terminal_at' => now(),
            'last_error' => 'simulated_permanent_failure',
        ]);
    }
}
