<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Exceptions\SanitizedProviderHttpException;

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

    public function normalize(mixed $response, ?SanitizedProviderHttpException $exception): ProviderNormalizedOutcome
    {
        if ($exception === null) {
            return ProviderNormalizedOutcome::success();
        }

        $category = $exception->category();

        if (in_array($category, self::UNCERTAIN_CATEGORIES, true)) {
            return ProviderNormalizedOutcome::uncertain($category);
        }

        return ProviderNormalizedOutcome::nonBillable($category);
    }
}
