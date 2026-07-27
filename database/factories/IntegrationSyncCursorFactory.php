<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Models\Firm;
use App\Services\EmailBodyEncryptionService;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * @extends Factory<IntegrationSyncCursor>
 *
 * integration_sync_cursors has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php),
 * so every INSERT (test or app) must run under the row's own
 * app.current_firm_id context. Mirrors FirmIntegrationFactory's
 * context-hold convention exactly.
 */
class IntegrationSyncCursorFactory extends Factory
{
    protected $model = IntegrationSyncCursor::class;

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
     * Firm::factory()->create() and
     * FirmIntegration::factory()->forFirm($firm)->create() as plain PHP
     * statements at the top of definition() — real, committed rows
     * every single time, even when forFirmIntegration() below
     * immediately overrides both firm_id and firm_integration_id with a
     * caller-supplied connection. Fixed by memoizing the pair behind
     * lazy closures so nothing is created unless at least one of those
     * keys survives, unoverridden, to the final row.
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
            'resource_type' => 'contact',
            'sync_direction' => SyncDirection::Inbound->value,
            'cursor_value' => null,
            'cursor_version' => 0,
            'status' => CursorStatus::Idle->value,
            'consecutive_failure_count' => 0,
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

    /**
     * Checkpoint 2 addition (checkpoint2-combined-design.md §2 P-13/P-14):
     * `cursor_value` is now encrypted at rest, and a DB CHECK constraint
     * (`integration_sync_cursors_value_key_id_pair`) requires
     * `cursor_value`/`cursor_value_encryption_key_id` to be set together.
     * Mirrors `SyncCursorService::advance()`'s own
     * `EmailBodyEncryptionService::encrypt()` call exactly, rather than
     * storing a plaintext value the real write path could never produce.
     * Resolves the firm from the attributes already merged in by
     * `firm_id`'s own definition() closure (a state closure receives the
     * attribute array resolved so far as its first parameter).
     */
    public function withCursorValue(string $value): static
    {
        return $this->state(function (array $attributes) use ($value): array {
            $firm = Firm::query()->findOrFail($attributes['firm_id']);
            $result = app(EmailBodyEncryptionService::class)->encrypt($firm, $value);

            if (! $result->succeeded) {
                throw new RuntimeException("IntegrationSyncCursorFactory::withCursorValue() could not encrypt: {$result->reason}");
            }

            return [
                'cursor_value' => $result->ciphertext,
                'cursor_value_encryption_key_id' => $result->encryptionKeyId,
            ];
        });
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => CursorStatus::Running->value,
            'locked_at' => now(),
        ]);
    }

    public function invalid(): static
    {
        return $this->state(fn () => [
            'status' => CursorStatus::Invalid->value,
            'cursor_value' => null,
        ]);
    }
}
