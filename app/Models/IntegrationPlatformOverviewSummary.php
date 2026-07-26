<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationPlatformOverviewSummary — Checkpoint 11 (frozen-design-
 * post-security-review.md §5). One row per firm, platform-owned,
 * carries NO RLS (see the create migration's own "WHY THIS TABLE HAS NO
 * RLS AND NO FORCE RLS" docblock) — deliberately does NOT use
 * App\Models\Concerns\BelongsToTenant: this table must remain readable
 * for every firm regardless of which (if any) tenant context happens to
 * be active in the admin panel, which never establishes one.
 *
 * Exactly one writer: App\Services\IntegrationPlatformOverviewSummaryService
 * ::refreshForFirm() — an upsert-only sole-writer, mirroring every other
 * sole-writer table in this mission. No other caller should ever
 * ->save()/->update() a row on this model directly.
 *
 * Every column is a pre-sanitized count/status/timestamp snapshot —
 * `computed_at` is the only staleness signal; this model is never
 * treated as a live, transactionally-consistent read of the underlying
 * tenant tables (see the migration's own docblock).
 */
class IntegrationPlatformOverviewSummary extends Model
{
    protected $table = 'integration_platform_overview_summaries';

    protected $fillable = [
        'firm_id',
        'firm_uuid',
        'connection_count_active',
        'connection_count_disconnected',
        'connection_count_other',
        'health_summary_state',
        'last_sync_outcome',
        'last_sync_at',
        'last_successful_sync_at',
        'failed_permanent_sync_item_count',
        'dead_lettered_outbox_event_count',
        'open_conflict_count',
        'entitlement_enabled',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'connection_count_active' => 'integer',
            'connection_count_disconnected' => 'integer',
            'connection_count_other' => 'integer',
            'last_sync_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'failed_permanent_sync_item_count' => 'integer',
            'dead_lettered_outbox_event_count' => 'integer',
            'open_conflict_count' => 'integer',
            'entitlement_enabled' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
