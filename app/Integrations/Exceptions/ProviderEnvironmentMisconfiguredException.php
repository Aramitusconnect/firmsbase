<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderEnvironmentMisconfiguredException — thrown exclusively by
 * App\Integrations\Support\ProviderEnvironmentResolver when a
 * ProviderKey has no usable `provider_environments` configuration (an
 * unset/invalid `mode`, or a missing `sandbox_base_url`/`live_base_url`
 * for the resolved mode) — never for a runtime provider failure.
 *
 * Deliberately NOT a SanitizedProviderHttpException: a misconfigured
 * environment is an operator/config bug, not something a provider did,
 * so it must never be mapped into that exception's retryable/terminal
 * category vocabulary (checkpoint1-combined-design.md §1, step 1) and
 * must never be silently retried by a job's own bounded-retry policy —
 * it should surface loudly and fail the operation immediately.
 *
 * Never includes a caller-supplied URL or any other potentially
 * sensitive value in its message beyond the closed ProviderKey value
 * and the fixed, developer-authored diagnostic text — mirrors every
 * sibling exception in this namespace's "never echo unvalidated input"
 * discipline.
 */
final class ProviderEnvironmentMisconfiguredException extends RuntimeException {}
