<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use App\Integrations\Enums\WebhookBootstrapState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;
use App\Services\TenantContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * BootstrapWebhookSubscriptionsJob — Checkpoint 8.2 §A7b/§A-bootstrap-retry.
 * Retries a webhook-subscription bootstrap that failed for a reason a
 * retry can plausibly fix.
 *
 * WHY IT EXISTS. The bootstrap used to run inside the OAuth completion
 * transaction, so its only failure mode was "roll the whole
 * authorization back and make the user start again". It now runs after
 * that transaction commits and degrades honestly instead, which means
 * something has to actually come back and finish the job — this is that
 * something.
 *
 * Scalar-ID-only constructor, mirroring `RenewGraphSubscriptionJob`
 * exactly: no hydrated model, no token, no credential id. `$firmId` is
 * carried deliberately because `firm_integrations` is FORCE-RLS'd, so a
 * fresh worker with no context cannot read the row to discover its owner.
 *
 * The work itself is delegated whole to
 * `ProviderConnectionService::retryWebhookBootstrap()`, which re-reads
 * fresh state, refuses states that need a human, and is the same entry
 * point a UI retry action uses. This job deliberately owns no logic of
 * its own beyond scheduling.
 *
 * FIXED (CP8.2 §A-bootstrap-retry) — `$tries`/`backoff()` used to be dead
 * code. `retryWebhookBootstrap()` caught EVERY exception internally and
 * always returned a `WebhookBootstrapState`, so `handle()` always
 * completed without throwing regardless of outcome — the queue counted
 * every attempt as a success and never applied backoff. `handle()` now
 * passes `rethrowRetryable: true`: a genuinely retryable failure is,
 * AFTER its state is durably persisted, rethrown, so `$tries`/`backoff()`
 * below actually govern the retry cadence. An uncertain outcome
 * (`bootstrap_reconciliation_required`) and a definite failure
 * (`bootstrap_failed`) are never rethrown — see
 * `ProviderConnectionService::runWebhookBootstrapAfterConnect()`'s own
 * docblock.
 */
final class BootstrapWebhookSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly int $currentUserId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ProviderConnectionService $connections): void
    {
        $connections->retryWebhookBootstrap(
            $this->firmIntegrationId, $this->firmId, $this->currentUserId, rethrowRetryable: true,
        );
    }

    /**
     * CHECKPOINT 8.2 (§A-bootstrap-retry) addition. Reached only once
     * $tries is exhausted for a genuinely retryable failure (every other
     * outcome already returns normally from handle() and never reaches
     * here). Persists an honest degraded state — never silently leaves
     * the connection looking healthy — and audits the exhaustion via the
     * same recordWebhookBootstrapState() path every other terminal
     * outcome already uses.
     */
    public function failed(?Throwable $exception): void
    {
        (new TenantContextService)->runWithFirmContext($this->firmId, function () {
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->first();

            if ($connection === null || ! $connection->webhook_bootstrap_state->isRetryable()) {
                // Already moved on (resolved, or already terminal) by the
                // time every retry exhausted.
                return;
            }

            app(ProviderConnectionService::class)->markWebhookBootstrapRetriesExhausted($connection);
        });
    }
}
