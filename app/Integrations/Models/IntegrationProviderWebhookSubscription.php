<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use Database\Factories\IntegrationProviderWebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IntegrationProviderWebhookSubscription — FirmsVault Live
 * Integrations, Checkpoint 2 (checkpoint2-design-sync-webhooks.md §3.2;
 * checkpoint2-combined-design.md §2 P-18). Direct firm-owned. The
 * durable home for a real provider's remote webhook subscription
 * state (id/expiry/resource scope) — no table anywhere in this
 * codebase persisted that before this checkpoint (see the create
 * migration's own docblock).
 *
 * No HasPublicUuid — internal connection state, never a firm-facing
 * activity-log surface, mirroring IntegrationSyncCursor's identical
 * choice (not IntegrationInboundWebhookEvent's, which IS firm-facing).
 *
 * Written by whatever orchestrates a successful `subscribe()` call
 * (Checkpoint 2's own connect-flow orchestration — the
 * Microsoft365Provider adapter itself is a later phase, out of this
 * checkpoint's scope) and by
 * App\Integrations\Jobs\RenewGraphSubscriptionJob (updates on
 * successful renewal; transitions to RenewalFailed once retries are
 * exhausted — see that job's own docblock).
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6).
 */
class IntegrationProviderWebhookSubscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'integration_provider_webhook_subscriptions';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'provider_key',
        'resource_type',
        'provider_resource',
        'provider_change_type',
        'provider_subscription_id',
        'expires_at',
        'status',
        'last_renewed_at',
        'last_renewal_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderWebhookSubscriptionStatus::class,
            'expires_at' => 'datetime',
            'last_renewed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationProviderWebhookSubscriptionFactory
    {
        return IntegrationProviderWebhookSubscriptionFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function isActive(): bool
    {
        return $this->status === ProviderWebhookSubscriptionStatus::Active;
    }
}
