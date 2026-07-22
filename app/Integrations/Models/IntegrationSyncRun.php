<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncRunType;
use App\Integrations\Enums\SyncTriggerSource;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use Database\Factories\IntegrationSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IntegrationSyncRun — one attempt to pull or push a resource_type/
 * direction pair against a firm_integrations connection (Checkpoint 6,
 * reviews/checkpoint-06/frozen-design-post-review.md §2/§4/§8). Direct
 * firm-owned. Status is mutated ONLY through the sole-writer
 * SyncRunService, mirroring ProviderConnectionService::transitionStatus()'s
 * precedent — never directly on this model from any other caller.
 *
 * `triggering_webhook_event_id` is deliberately NOT a column on this
 * table at Checkpoint 6 — see the create migration's docblock.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6): the default
 * Model::resolveFactoryName() only special-cases the literal
 * `App\Models\` prefix.
 */
class IntegrationSyncRun extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'integration_sync_runs';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'resource_type',
        'sync_direction',
        'run_type',
        'trigger_source',
        'status',
        'retried_run_id',
        'cancel_requested_at',
        'items_total',
        'items_succeeded',
        'items_failed',
        'items_skipped',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_direction' => SyncDirection::class,
            'run_type' => SyncRunType::class,
            'trigger_source' => SyncTriggerSource::class,
            'status' => SyncRunStatus::class,
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationSyncRunFactory
    {
        return IntegrationSyncRunFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function retriedRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retried_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IntegrationSyncItem::class, 'sync_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            SyncRunStatus::Succeeded,
            SyncRunStatus::PartialFailure,
            SyncRunStatus::Failed,
            SyncRunStatus::Cancelled,
        ], true);
    }
}
