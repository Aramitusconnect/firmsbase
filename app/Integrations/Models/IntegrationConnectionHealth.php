<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\HealthSummaryState;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationConnectionHealth — one row per `firm_integrations`
 * connection, the operational health signal store (Checkpoint 8,
 * agent-8f-health-state-design.md §1/§3). Direct firm-owned, FORCE RLS.
 * `summary_state`/`consecutive_failures`/`last_failure_category`/etc.
 * are mutated ONLY through the sole-writer
 * App\Integrations\Services\HealthStateService's guarded upsert — never
 * via a plain Eloquent ->update() call from any other caller, mirroring
 * every other sole-writer table in this mission.
 */
class IntegrationConnectionHealth extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $table = 'integration_connection_health';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'summary_state',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
        'last_failure_category',
        'rate_limited_reset_at',
        'next_retry_at',
        'sanitized_diagnostic_summary',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'summary_state' => HealthSummaryState::class,
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'rate_limited_reset_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }
}
