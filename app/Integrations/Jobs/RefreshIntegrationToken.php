<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use App\Integrations\Data\OAuthCallbackResult;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;

/**
 * RefreshIntegrationToken — a synchronously-invokable service-level
 * wrapper around ProviderConnectionService::refreshConnectionToken(),
 * per checkpoint-00-final-specification.md's explicit directive and
 * this checkpoint's frozen scope: "a synchronously-invokable class only
 * — do not wire it onto any queue connection/worker; Checkpoint 8 owns
 * production dispatch."
 *
 * DELIBERATELY does NOT implement Illuminate\Contracts\Queue\ShouldQueue,
 * does NOT use the Dispatchable/InteractsWithQueue/Queueable traits, and
 * is never registered against any queue connection anywhere in this
 * checkpoint. This is a plain, directly-callable PHP class — proven only
 * by tests calling handle() directly (or via the container) — NOT a
 * real Laravel queue job in this checkpoint, even though it lives under
 * app/Integrations/Jobs and is named for the role it will eventually
 * play once Checkpoint 8 builds the actual production queue-dispatch
 * wiring around it (a future ShouldQueue wrapper, or a direct promotion
 * of this class, is Checkpoint 8's decision to make — not pre-empted
 * here).
 *
 * Named "RefreshIntegrationToken" (not
 * "RefreshIntegrationTokenJob") per the frozen file allowlist's exact
 * filename.
 */
final class RefreshIntegrationToken
{
    public function __construct(private readonly ProviderConnectionService $connectionService)
    {
    }

    public function handle(FirmIntegration $connection): OAuthCallbackResult
    {
        return $this->connectionService->refreshConnectionToken($connection);
    }
}
