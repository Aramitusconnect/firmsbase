<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Services\HealthStateService;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * RenewGraphSubscriptionJob — FirmsVault Live Integrations, Checkpoint 2
 * (checkpoint2-design-sync-webhooks.md §3.3; checkpoint2-combined-design.md
 * §2 P-19). Structurally mirrors
 * App\Integrations\Jobs\RefreshIntegrationToken exactly (scalar-FK-only
 * constructor, TenantAwareJobContext, backoff() array, category-aware
 * failed() hook) — but is PROACTIVE/schedule-driven
 * (dispatched by App\Console\Commands\RenewProviderWebhookSubscriptionsCommand),
 * never reactive. Graph subscriptions have no automatic renewal; a
 * Microsoft connection that is healthy in every other respect will
 * still silently stop receiving webhooks the moment its subscription
 * expires, with no other code path that would notice.
 *
 * Constructor carries three bare, non-secret integer FKs ONLY — never a
 * token, never a credential ID, never a hydrated model. $firmId is
 * included deliberately, not a violation of "connection/subscription ID
 * only": both firm_integrations and integration_provider_webhook_subscriptions
 * are FORCE-RLS'd, so a fresh worker process with zero context cannot
 * safely read either to discover which firm owns them.
 *
 * Re-verify-fresh-state-first discipline (design §3.3, mirrors
 * RefreshIntegrationToken's own "Gate 1" doc comment): re-resolves BOTH
 * the connection AND the subscription row fresh from the database as
 * its first action, silently no-oping (never throwing, never counted
 * against $tries) if the connection is no longer Active or the
 * subscription row is no longer `active` — a connection disconnected,
 * or a subscription already superseded/failed, between schedule-time
 * and job-execution-time must never be renewed.
 *
 * Provider-agnostic despite the class name (kept per the design
 * document's own naming, which frames this as "Microsoft specifically"
 * for now): resolves the provider polymorphically via ProviderRegistry
 * + instanceof SupportsWebhooksContract, never branching on provider
 * identity — the identical shape every other job in this codebase
 * (PullSyncJob, PushSyncJob) already uses. Reused unmodified by a
 * future Google `watch()`-channel adapter, which has the identical
 * "remote subscription with an expiry that must be renewed" shape.
 */
final class RenewGraphSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    /**
     * Matches RefreshIntegrationToken's own $tries — same
     * WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts']
     * shape reused by convention, not by direct dependency.
     */
    public int $tries = 5;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly int $subscriptionId,
    ) {}

    /**
     * Fixed schedule, byte-for-byte identical to RefreshIntegrationToken's
     * own backoff() — Laravel's native mechanism, a fixed array, not a
     * jitter/category-aware computation.
     */
    public function backoff(): array
    {
        return [30, 60, 120, 240];
    }

    public function handle(ProviderRegistry $registry, HealthStateService $healthStateService): void
    {
        $this->runInFirmContext($this->firmId, function () use ($registry, $healthStateService): void {
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->first();

            if ($connection === null) {
                // Connection deleted since dispatch (e.g. cascade-deleted
                // with its firm) — nothing to do, no error, never
                // counted against $tries.
                return;
            }

            if ((int) $connection->firm_id !== $this->firmId) {
                // Should be structurally impossible once real tenant
                // context is active (RLS would already exclude the row)
                // — kept as an explicit, cheap defense-in-depth
                // assertion, never trusting a single layer alone.
                throw new RuntimeException(
                    "Connection {$this->firmIntegrationId} does not belong to firm {$this->firmId}."
                );
            }

            // Gate 1 — re-resolved fresh, before acquiring any lock. A
            // connection disconnected between schedule-time and
            // execution-time must silently no-op, never renew a
            // subscription for a connection that no longer has usable
            // credentials.
            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            $subscription = IntegrationProviderWebhookSubscription::query()
                ->where('id', $this->subscriptionId)
                ->where('firm_integration_id', $this->firmIntegrationId)
                ->lockForUpdate()
                ->first();

            // Gate 1's second half — a subscription already superseded
            // (renewed by a concurrent tick), already failed, or already
            // removed must never be re-renewed here.
            if ($subscription === null || $subscription->status !== ProviderWebhookSubscriptionStatus::Active) {
                return;
            }

            $providerCode = $connection->integrationProvider?->code;

            if ($providerCode === null) {
                return;
            }

            $provider = $registry->get(ProviderKey::from($providerCode));

            if (! $provider instanceof SupportsWebhooksContract) {
                // Defensive: the connection's registered provider no
                // longer (or never did) support webhooks. Nothing this
                // job can meaningfully do — surfacing as a hard failure
                // would just burn retries against a structural mismatch,
                // not a transient condition.
                return;
            }

            try {
                $result = $provider->renewSubscription([
                    'connection' => $connection,
                    'subscription' => $subscription,
                ]);
            } catch (SanitizedProviderHttpException $e) {
                // Design §3.3: a genuine 404 (subscription already gone
                // at the provider — deleted after expiry, or never
                // created due to a prior partial failure) should trigger
                // a fresh subscribe() call instead of retrying a
                // renewal against a dead subscription id. Deliberately
                // narrowed to statusCode() === 404, not the whole
                // CATEGORY_PROVIDER_REJECTED bucket (which also covers
                // 5xx) — a transient 5xx does not mean the subscription
                // is actually gone, and treating it as such would create
                // an unnecessary duplicate subscription while Graph is
                // merely erroring transiently. Any other category
                // (including a non-404 CATEGORY_PROVIDER_REJECTED)
                // rethrows unchanged, letting this job's own $tries/
                // backoff() retry it as an ordinary renewal.
                if ($e->category() === SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED && $e->statusCode() === 404) {
                    $result = $provider->subscribe([
                        'connection' => $connection,
                        'resource_type' => $subscription->resource_type,
                        'provider_resource' => $subscription->provider_resource,
                        'provider_change_type' => $subscription->provider_change_type,
                    ]);
                } else {
                    throw $e;
                }
            }

            [$providerSubscriptionId, $expiresAt] = $this->extractSubscriptionState($result);

            $subscription->forceFill([
                'provider_subscription_id' => $providerSubscriptionId,
                'expires_at' => $expiresAt,
                'status' => ProviderWebhookSubscriptionStatus::Active,
                'last_renewed_at' => now(),
                'last_renewal_error' => null,
            ])->save();

            $healthStateService->recordSuccess($connection->id, $this->firmId);
        });
    }

    /**
     * subscribe()/renewSubscription() (SupportsWebhooksContract) return
     * only an open array<string, mixed> — "subscription state (e.g.
     * subscription id, expiry)", no fixed key names guaranteed by the
     * interface. A missing/unparseable required field is treated as a
     * malformed-response failure (an uncaught RuntimeException here
     * propagates out of handle() exactly like any other transient
     * failure, retried via this job's own $tries/backoff() — never
     * silently persisted as a NULL against this table's NOT NULL
     * expires_at column).
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: Carbon}
     */
    private function extractSubscriptionState(array $result): array
    {
        $subscriptionId = $result['subscription_id'] ?? null;
        $expiresAtRaw = $result['expires_at'] ?? null;

        if (! is_string($subscriptionId) || trim($subscriptionId) === '') {
            throw new RuntimeException('Provider returned a subscription result with no usable subscription_id.');
        }

        if (! is_string($expiresAtRaw) && ! $expiresAtRaw instanceof \DateTimeInterface) {
            throw new RuntimeException('Provider returned a subscription result with no usable expires_at.');
        }

        try {
            $expiresAt = Carbon::parse($expiresAtRaw);
        } catch (Throwable) {
            throw new RuntimeException('Provider returned an unparseable expires_at value.');
        }

        return [$subscriptionId, $expiresAt];
    }

    /**
     * Reached only once $tries is exhausted for a category that was
     * rethrown above (or once extractSubscriptionState()'s malformed-
     * response guard is exhausted). Runs outside handle()'s own
     * transaction, so tenant context is re-established fresh — mirrors
     * RefreshIntegrationToken::failed() exactly.
     */
    public function failed(?Throwable $exception): void
    {
        $this->runInFirmContext($this->firmId, function () use ($exception): void {
            $subscription = IntegrationProviderWebhookSubscription::query()
                ->where('id', $this->subscriptionId)
                ->where('firm_integration_id', $this->firmIntegrationId)
                ->first();

            if ($subscription === null || $subscription->status !== ProviderWebhookSubscriptionStatus::Active) {
                // Already handled/moved on by the time all retries
                // exhausted (renewed by a concurrent tick, already
                // failed, or already removed).
                return;
            }

            $category = $exception instanceof SanitizedProviderHttpException
                ? $exception->category()
                : SanitizedProviderHttpException::CATEGORY_UNKNOWN;

            $subscription->update([
                'status' => ProviderWebhookSubscriptionStatus::RenewalFailed,
                'last_renewal_error' => $category,
            ]);

            app(HealthStateService::class)->recordProviderError(
                $this->firmIntegrationId,
                $this->firmId,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_WEBHOOK_SUBSCRIBE,
                ),
            );
        });
    }
}
