<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use App\Integrations\Services\ProviderConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * BootstrapWebhookSubscriptionsJob — Checkpoint 8.2 §A7b. Retries a
 * webhook-subscription bootstrap that failed for a reason a retry can
 * plausibly fix.
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
 * `$tries`/`backoff()` mirror RenewGraphSubscriptionJob's shape. There is
 * no `failed()` hook: exhausting the retries leaves the connection in
 * `bootstrap_pending_retry`, which is already an honest, visible state —
 * inventing a different terminal state here would claim knowledge this
 * job does not have.
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
        $connections->retryWebhookBootstrap($this->firmIntegrationId, $this->firmId, $this->currentUserId);
    }
}
