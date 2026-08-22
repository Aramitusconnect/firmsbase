<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;

/**
 * OAuthCallbackResult — returned by
 * ProviderConnectionService::completeOAuthCallback() to the HTTP layer.
 * Carries only the already-firm-scoped $firmIntegration (from which the
 * controller computes the single, hardcoded, deterministic post-callback
 * redirect destination — e.g. route('firm.integrations.show',
 * $firmIntegration) — never a stored or request-suppliable redirect
 * value; see the frozen design's item 11 rejection of any
 * `redirect_intent` mechanism), the resulting ConnectionStatus, a
 * success flag, and an OPTIONAL, already-sanitized, human-readable
 * error message safe to flash to the browser (never raw provider
 * response text, never a stack trace).
 *
 * $transitionedThisCall (Checkpoint 8 bugfix, diff-review §5 item 5):
 * true ONLY when THIS specific call's own execution just performed a
 * genuine `invalid_grant` -> ReauthorizationRequired transition inside
 * refreshConnectionToken()'s catch block. Defaults to false everywhere
 * else, including refreshConnectionToken()'s Gate 2 no-op path (the
 * connection was already non-Active — e.g. already
 * ReauthorizationRequired from a DIFFERENT, earlier transition — before
 * this call ever started doing any work), which would otherwise be
 * indistinguishable from a genuine same-call transition to a caller
 * that only inspects $status/$successful. RefreshIntegrationToken::handle()
 * relies on this flag to avoid calling
 * HealthStateService::recordCredentialError() for a no-op.
 */
final class OAuthCallbackResult
{
    public function __construct(
        public readonly FirmIntegration $firmIntegration,
        public readonly ConnectionStatus $status,
        public readonly bool $successful,
        public readonly ?string $errorMessage = null,
        public readonly bool $transitionedThisCall = false,
    ) {}
}
