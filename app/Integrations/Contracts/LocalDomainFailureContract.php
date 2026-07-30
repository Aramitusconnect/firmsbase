<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * LocalDomainFailureContract — a marker for an exception that describes a
 * LOCAL, definite domain failure rather than anything that happened at, or
 * on the way to, a provider (Checkpoint 8.2 corrective pass).
 *
 * WHY THIS EXISTS. `OutboundProviderHttpClient::execute()` ends in a
 * `catch (Throwable)` that converts anything unrecognised into
 * `SanitizedProviderHttpException(CATEGORY_UNKNOWN)`. That is right for
 * request construction, transport, and response handling — the three
 * things it is there to sanitize — but wrong for a failure raised by local
 * domain logic that runs inside the same closure and never reached the
 * network.
 *
 * The concrete case that motivated it: a Gmail mailbox already routed to
 * another firm's connection. That is a definite local ownership conflict,
 * detected BEFORE the watch request is sent, with nothing at the provider
 * to reconcile. Sanitizing it into `UNKNOWN` made callers classify it as
 * an AMBIGUOUS PROVIDER OUTCOME — the one classification that must never
 * be auto-retried — so a cross-firm conflict parked the connection in a
 * reconciliation state with no correct resolution, instead of failing
 * cleanly and telling the firm what was wrong.
 *
 * An exception implementing this marker is rethrown unchanged by that
 * boundary. Implement it ONLY when all three hold:
 *
 *   1. the failure is raised by local logic, not by a provider response;
 *   2. no network request was made, or the failure is provably unrelated
 *      to one that was;
 *   3. the exception carries no credential, token, or provider payload —
 *      it crosses the sanitizing boundary intact, so it must already be
 *      safe to surface.
 *
 * This is deliberately a typed contract rather than a class allowlist in
 * the HTTP client: an allowlist grows silently and invites unrelated
 * additions, whereas implementing this interface is a decision made at the
 * exception, next to the three conditions it has to satisfy.
 */
interface LocalDomainFailureContract
{
    /**
     * A short, stable, sanitized reason code for classification and audit
     * — never a message intended for a provider's own error text.
     */
    public function localFailureReason(): string;
}
