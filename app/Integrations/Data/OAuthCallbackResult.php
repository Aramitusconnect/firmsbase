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
 */
final class OAuthCallbackResult
{
    public function __construct(
        public readonly FirmIntegration $firmIntegration,
        public readonly ConnectionStatus $status,
        public readonly bool $successful,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
