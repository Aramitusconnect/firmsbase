<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\SyncDirection;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use Database\Factories\IntegrationExternalMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationExternalMapping — the durable local<->external identity
 * bridge (Checkpoint 6, reviews/checkpoint-06/frozen-design-post-review.md
 * §3/§6/§8). Direct firm-owned, long/permanent retention — never
 * hard-deleted. Tombstoned via `tombstoned_at`/`tombstone_reason`
 * instead; this model deliberately exposes no delete()/forceDelete()
 * call site of its own — application code must go through
 * IntegrationExternalMappingService::tombstone(), never
 * Model::delete().
 *
 * `firm_integration_id` is the LEADING column of both this table's
 * uniqueness indexes (never `firm_id` alone) — the sole guarantee that
 * prevents two connections of the SAME firm from conflating identical
 * external IDs, a case where RLS provides zero protection (see the
 * create migration's docblock).
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationExternalMapping extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'integration_external_mappings';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'resource_type',
        'local_type',
        'local_id',
        'external_id',
        'external_version_token',
        'local_version_token',
        'sync_direction',
        'last_synced_at',
        'tombstoned_at',
        'tombstone_reason',
    ];

    protected function casts(): array
    {
        return [
            'sync_direction' => SyncDirection::class,
            'last_synced_at' => 'datetime',
            'tombstoned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationExternalMappingFactory
    {
        return IntegrationExternalMappingFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function isTombstoned(): bool
    {
        return $this->tombstoned_at !== null;
    }
}
