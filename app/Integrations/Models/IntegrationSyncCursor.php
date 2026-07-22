<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\SyncDirection;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use Database\Factories\IntegrationSyncCursorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationSyncCursor — the ONE table among Checkpoint 6's six that
 * is mutated in place, not append-only (reviews/checkpoint-06/frozen-
 * design-post-review.md §8; agent-6e-sync-run-item-cursor-semantics.md
 * §2-§4). Direct firm-owned. `cursor_value` may change ONLY inside the
 * same database transaction that commits the batch's terminal
 * item-status writes — the single most important invariant in this
 * checkpoint's design — enforced by the sole-writer SyncCursorService,
 * never mutated directly on this model from any other caller.
 *
 * `cursor_version` is the Layer-2 optimistic-concurrency guard (Layer 1
 * is the partial unique index on integration_sync_runs) — every
 * advancing UPDATE is a compare-and-swap on this column.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationSyncCursor extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'integration_sync_cursors';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'resource_type',
        'sync_direction',
        'cursor_value',
        'cursor_version',
        'status',
        'locked_by_sync_run_id',
        'locked_at',
        'consecutive_failure_count',
        'cursor_issued_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_direction' => SyncDirection::class,
            'status' => CursorStatus::class,
            'locked_at' => 'datetime',
            'cursor_issued_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationSyncCursorFactory
    {
        return IntegrationSyncCursorFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function lockedBySyncRun(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncRun::class, 'locked_by_sync_run_id');
    }

    public function isLocked(): bool
    {
        return $this->status === CursorStatus::Running;
    }
}
