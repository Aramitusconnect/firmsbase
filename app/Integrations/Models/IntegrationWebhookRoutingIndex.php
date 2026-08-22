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
    ];

    protected $hidden = [
        'webhook_routing_token_hash',
    ];

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
