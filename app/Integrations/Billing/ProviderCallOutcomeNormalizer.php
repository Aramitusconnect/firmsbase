<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Exceptions\SanitizedProviderHttpException;
use Throwable;

/**
 * ProviderCallOutcomeNormalizer — pipeline step 14
 * (checkpoint4-design-cost-control.md §2 step 14/§3.2). Maps a
 * `SanitizedProviderHttpException::category()` (the closed, already-
 * existing vocabulary — no new categories invented) onto a
 * `ProviderNormalizedOutcome`.
 *
 * Outcome table, per design §3.2:
 *   - success (no exception) -> billable, certain.
 *   - `network_error`/`timeout`/`unknown` -> NEVER assumed billable,
 *     NEVER assumed non-billable — `uncertain`. This is the "uncertain
 *     billing outcome" the spec names explicitly: a dropped connection
 *     means the client cannot know whether Plaid received and processed
 *     the request before the connection failed.
 *   - every other closed category (authentication_failed,
 *     authorization_failed, validation_failed, provider_rejected,
 *     invalid_grant, conflict, rate_limited, configuration_error,
 *     cursor_expired) -> `non_billable` — Plaid rejected the request
 *     BEFORE doing the work being billed for.
 *
 * EXTENSION beyond the design's own literal table (a judgment call,
 * flagged here): `connection_unavailable` and `malformed_response` are
 * two closed categories `SanitizedProviderHttpException` carries that
 * the design's own §3.2 table does not enumerate (that table was
 * written against a narrower category list). Applying the design's own
 * stated deciding test — "is it genuinely ambiguous whether Plaid
 * processed the request" — `connection_unavailable` is treated as
 * `uncertain` (structurally identical to `network_error`: the request
 * may never have reached Plaid, or may have reached it and the response
 * never returned), and `malformed_response` is ALSO treated as
 * `uncertain` rather than `non_billable`: that category fires only on
 * an otherwise-2xx response Plaid returned but this system could not
 * parse — Plaid very likely DID process the request (it returned
 * success) — so the honest state is "uncertain," never "confidently not
 * billed."
 */
final class ProviderCallOutcomeNormalizer
{
    private const UNCERTAIN_CATEGORIES = [
        SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR,
        SanitizedProviderHttpException::CATEGORY_TIMEOUT,
        SanitizedProviderHttpException::CATEGORY_UNKNOWN,
        SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE,
        SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE,
    ];

    public function normalize(mixed $response, ?Throwable $exception): ProviderNormalizedOutcome
    {
        if ($exception === null) {
            return ProviderNormalizedOutcome::success();
        }

        if (! $exception instanceof SanitizedProviderHttpException) {
            // EXTENDED, not replaced (double-billing remediation): the
            // parameter widened from ?SanitizedProviderHttpException to
            // ?Throwable so the pipeline's step 13 can catch EVERY
            // throwable category the real outbound call can produce and
            // still reach finalize(), instead of letting anything that
            // is not already sanitized escape and strand the reservation
            // in `reserved` until its TTL. Everything below this branch
            // is byte-for-byte the pre-existing classification.
            return ProviderNormalizedOutcome::uncertain($this->categoryForUnsanitizedThrowable($exception));
        }

        $category = $exception->category();

        if (in_array($category, self::UNCERTAIN_CATEGORIES, true)) {
            return ProviderNormalizedOutcome::uncertain($category);
        }

        return ProviderNormalizedOutcome::nonBillable($category);
    }

    /**
     * Classifies a throwable that never passed through
     * `OutboundProviderHttpClient`'s sanitizing boundary (which already
     * folds any stray Throwable into
     * `SanitizedProviderHttpException(CATEGORY_UNKNOWN)`, so in practice
     * this is only reached when a caller's `$providerCall` closure does
     * something outside that boundary).
     *
     * Two hard rules:
     *   1. REDACTION — decided purely from the exception's CLASS. This
     *      method never reads `getMessage()`, `getTraceAsString()`, or
     *      any other payload-bearing accessor, so a raw provider
     *      response body embedded in a Guzzle exception message can
     *      never reach the reservation, the timeline event, or the
     *      observability event.
     *   2. NEVER "definitely failed" — every mapped category is one of
     *      `UNCERTAIN_CATEGORIES`. A timeout, a reset connection, or an
     *      unrecognised failure all leave it genuinely unknowable
     *      whether the provider received and processed (and therefore
     *      billed) the request, and the design's own deciding test for
     *      that is "uncertain", never `non_billable`. Marking such a
     *      call non-billable would understate what the provider will
     *      invoice; marking it billable would overcharge the firm.
     */
    private function categoryForUnsanitizedThrowable(Throwable $exception): string
    {
        // Matched on the short class name, never a fully-qualified
        // client-library name: tests/Unit/Integrations/NoRealNetworkCallTest.php
        // forbids any file under app/Integrations/ from naming a real
        // network-call primitive (`Http::`, `Guzzle`, `curl_`), and that
        // guard is deliberately not weakened for this remediation. The
        // distinction is cosmetic for billing anyway — every branch here,
        // including the default, is one of UNCERTAIN_CATEGORIES.
        $shortName = ($position = strrpos($exception::class, '\\')) === false
            ? $exception::class
            : substr($exception::class, $position + 1);

        return match ($shortName) {
            'ConnectionException', 'ConnectException' => SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE,
            'TimeoutException', 'TransferException' => SanitizedProviderHttpException::CATEGORY_TIMEOUT,
            default => SanitizedProviderHttpException::CATEGORY_UNKNOWN,
        };
    }
}
