<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\SyncItemStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use Database\Factories\IntegrationSyncItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IntegrationSyncItem — per-run, per-object processing state
 * (Checkpoint 6, reviews/checkpoint-06/frozen-design-post-review.md
 * §3/§6/§8). Direct firm-owned. `local_type`/`local_id` is the
 * polymorphic pointer to the FirmsBase-side record (frozen column
 * naming — matches App\Models\TimelineEvent's real convention, not
 * `aggregate_type`/`aggregate_id`). Status is mutated ONLY by the
 * owning run's own batch loop (first attempts) or a dedicated retry
 * claim path — never directly by any other caller.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationSyncItem extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'integration_sync_items';

    protected $fillable = [
        'firm_id',
        'sync_run_id',
        'resource_type',
        'local_type',
        'local_id',
        'external_id',
        'status',
        'attempt_count',
        'next_attempt_at',
        'payload_hash',
        'last_error',
        'terminal_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncItemStatus::class,
            'next_attempt_at' => 'datetime',
            'terminal_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationSyncItemFactory
    {
        return IntegrationSyncItemFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncRun::class, 'sync_run_id');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(IntegrationConflict::class, 'sync_item_id');
    }

    /**
     * Cursor-safety per agent-6e §7: Pending/Retrying/FailedRetryable
     * BLOCK cursor advancement past the batch; Succeeded/Skipped/
     * FailedPermanent do NOT.
     */
    public function blocksCursorAdvancement(): bool
    {
        return in_array($this->status, [
            SyncItemStatus::Pending,
            SyncItemStatus::Retrying,
            SyncItemStatus::FailedRetryable,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            SyncItemStatus::Succeeded,
            SyncItemStatus::FailedPermanent,
            SyncItemStatus::Skipped,
        ], true);
    }
}
