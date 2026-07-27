<?php

declare(strict_types=1);

namespace App\Models;

use App\Integrations\Models\IntegrationProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationPlatformProviderHealthSummary — Phase 2 of the FirmsVault
 * Platform Admin Control Center mission ("Integration Operations
 * Center"). One row per provider, platform-owned, carries NO RLS (see
 * the create migration's own "WHY THIS TABLE HAS NO RLS AND NO FORCE
 * RLS" docblock) — mirrors App\Models\IntegrationPlatformOverviewSummary's
 * shape one grain narrower (per-provider rollup, not per-firm).
 * Deliberately does NOT use App\Models\Concerns\BelongsToTenant: there
 * is no firm_id column on this table at all, and this table must
 * remain readable for every provider regardless of which (if any)
 * tenant context happens to be active in the admin panel.
 *
 * Exactly one writer:
 * App\Services\IntegrationPlatformProviderHealthSummaryService
 * ::refreshForProvider() — an upsert-only sole-writer, mirroring every
 * other sole-writer table in this mission. No other caller should ever
 * ->save()/->update() a row on this model directly.
 *
 * Every column is a pre-sanitized count/status/timestamp snapshot —
 * `computed_at` is the only staleness signal; this model is never
 * treated as a live, transactionally-consistent read of the underlying
 * tenant tables.
 */
class IntegrationPlatformProviderHealthSummary extends Model
{
    protected $table = 'integration_platform_provider_health_summaries';

    protected $fillable = [
        'integration_provider_id',
        'provider_code',
        'provider_enabled',
        'connected_firm_count',
        'disconnected_firm_count',
        'firms_requiring_attention_count',
        'oauth_health_signal',
        'webhook_health_signal',
        'rate_limit_condition_signal',
        'recent_error_classification_summary',
        'computed_at',
        // Checkpoint 1 (FirmsVault Live Integrations,
        // checkpoint1-design-health-sandbox.md §A.3.2) additions — see
        // database/migrations/2026_09_13_130003_add_metrics_columns_to_integration_platform_provider_health_summaries_table.php.
        'total_request_count',
        'total_success_count',
        'throttled_connection_count',
        'token_refresh_failure_count',
        'webhook_verification_failure_count',
        'dead_letter_count',
        'avg_latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'provider_enabled' => 'boolean',
            'connected_firm_count' => 'integer',
            'disconnected_firm_count' => 'integer',
            'firms_requiring_attention_count' => 'integer',
            'recent_error_classification_summary' => 'array',
            'computed_at' => 'datetime',
            'total_request_count' => 'integer',
            'total_success_count' => 'integer',
            'throttled_connection_count' => 'integer',
            'token_refresh_failure_count' => 'integer',
            'webhook_verification_failure_count' => 'integer',
            'dead_letter_count' => 'integer',
            'avg_latency_ms' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'integration_provider_id');
    }
}
