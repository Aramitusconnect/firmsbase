<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Firm;
use Database\Factories\IntegrationWebhookRoutingIndexFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationWebhookRoutingIndex — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §5.1).
 * NO RLS at all — see this model's own create migration
 * (database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php)
 * for the full, required-reading "WHY THIS TABLE HAS NO RLS" reasoning.
 * Deliberately does NOT use App\Models\Concerns\BelongsToTenant (that
 * trait's global scope assumes/enforces an RLS-protected, firm-scoped
 * read pattern this table's entire purpose is to be exempt from).
 *
 * Written ONLY by
 * App\Integrations\Services\ProviderConnectionService::enableWebhookRouting()/
 * disableWebhookRouting(), and read ONLY by
 * App\Integrations\Services\WebhookConnectionResolverService's Step 1
 * pre-tenant lookup. No other caller should query or mutate this
 * table directly.
 *
 * No `uuid` column, no HasPublicUuid: internal routing-identity state,
 * never externally addressed (matches `integration_oauth_states`'s
 * identical no-uuid precedent).
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6): the default
 * Model::resolveFactoryName() only special-cases the literal
 * `App\Models\` prefix.
 */
class IntegrationWebhookRoutingIndex extends Model
{
    use HasFactory;

    protected $table = 'integration_webhook_routing_index';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'integration_provider_id',
        'webhook_routing_token_hash',
        // FirmsVault Pay Gate A2 — mode B (provider-resource ownership).
        // See 2026_11_21_100001_add_provider_resource_ownership_to_
        // integration_webhook_routing_index_table.php.
        'provider_resource_type',
        'provider_resource_id',
        'ownership_status',
        'ownership_established_at',
    ];

    protected $hidden = [
        'webhook_routing_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'ownership_established_at' => 'datetime',
        ];
    }

    /**
     * FirmsVault Pay Gate A2 ownership immutability (v1.4 §7). Tenant
     * ownership of an external provider resource, once established, can
     * never be reassigned through a normal application path:
     * ACTIVE -> INACTIVE is allowed, Firm A -> Firm B is not.
     *
     * This is the APPLICATION half. The database half — and the half
     * that matters most, because it blocks the realistic
     * "deactivate then let another firm claim it" route — is the partial
     * unique index `integration_webhook_routing_index_resource_ownership_unique`,
     * which deliberately does NOT exclude inactive rows.
     *
     * A database trigger is deliberately NOT used: this codebase has a
     * standing zero-trigger convention (stated verbatim in
     * 2026_09_05_054001_create_integration_conflicts_table.php), and
     * column-level REVOKE UPDATE would not bind the table owner, which
     * is the role both the application and the test harness connect as.
     */
    protected static function booted(): void
    {
        static::updating(function (self $row) {
            $changed = array_intersect(
                array_keys($row->getDirty()),
                ['firm_id', 'firm_integration_id', 'integration_provider_id', 'provider_resource_type', 'provider_resource_id'],
            );

            if ($changed !== []) {
                throw new \LogicException(
                    'integration_webhook_routing_index: provider-resource tenant ownership is immutable — '
                    .'refusing to change ['.implode(', ', $changed).']. Deactivate the row '
                    .'(ownership_status = inactive) instead; historical ownership must remain provable.'
                );
            }
        });

        static::deleting(function (self $row) {
            // Mode-A (routing token) rows are still freely deletable —
            // ProviderConnectionService has always deleted them on
            // disable/disconnect and that behavior is unchanged.
            // Mode-B ownership rows are historical financial evidence.
            if ($row->provider_resource_id !== null) {
                throw new \LogicException(
                    'integration_webhook_routing_index: a provider-resource ownership row can never be '
                    .'deleted — it is the authoritative, historically immutable record of which firm '
                    .'owned this external provider resource.'
                );
            }
        });
    }

    protected static function newFactory(): IntegrationWebhookRoutingIndexFactory
    {
        return IntegrationWebhookRoutingIndexFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function integrationProvider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class);
    }
}
