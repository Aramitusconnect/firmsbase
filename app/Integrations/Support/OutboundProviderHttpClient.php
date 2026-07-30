<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Contracts\LocalDomainFailureContract;
use App\Integrations\Exceptions\AuthorizationCodeAlreadyUsedException;
use App\Integrations\Exceptions\ExpiredAuthorizationCodeException;
use App\Integrations\Exceptions\InvalidPkceVerifierException;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * OutboundProviderHttpClient — the sanitizing boundary EVERY outbound,
 * HTTP-shaped provider call in this checkpoint must pass through before
 * any exception can reach a logger, a TimelineEventRecorder metadata
 * array, or a failed_jobs row (agent-h-security-architecture-review.md
 * item 19; checkpoint-00-final-specification.md §6/§10 — confirmed a
 * genuine, previously-missing gap: App\Integrations\Support\ did not
 * exist before this checkpoint).
 *
 * Deliberately holds NO reference anywhere to a real HTTP client
 * (`Illuminate\Support\Facades\Http`, GuzzleHttp, curl_*, etc.) itself —
 * this class never imports Http:: and never will. This is NOT the same
 * claim as "zero real-HTTP-code exists anywhere in this codebase" —
 * that premise flipped from true to false the moment Checkpoint 1
 * (FirmsVault Live Integrations) shipped
 * `App\Integrations\Support\ProviderRequestExecutor`, the one
 * designated, reviewed exception `tests/Unit/Integrations/NoRealNetworkCallTest.php`
 * now carves out. `execute()` stays green under that test not because
 * no real HTTP client exists, but because THIS class specifically still
 * doesn't reference one: a provider adapter's `push()`/`pull()`/
 * `refreshToken()` method (Checkpoints 2-5) calls `ProviderRequestExecutor::send()`
 * internally to make the real call, and the resulting, already-sanitized
 * `SanitizedProviderHttpException` simply propagates up through the
 * closure this method wraps — see the first catch group below, which
 * now rethrows that exception type unchanged rather than letting it
 * fall into the generic `catch (Throwable)` branch and lose its real
 * category.
 *
 * Catches, in order:
 *   1. Exception types this codebase ALREADY defines to be safe,
 *      app-level, non-leaking (never raw provider response text) —
 *      rethrown completely unchanged, since sanitizing them further
 *      would only destroy information a caller legitimately needs
 *      (e.g. ProviderConnectionService branching on
 *      InvalidPkceVerifierException vs OAuthAccountMismatchException,
 *      or a job-level catch branching on SanitizedProviderHttpException::category()).
 *   2. SimulatedProviderFailureException — TestProvider's stand-in for
 *      "a real provider's outbound call failed" (see that exception's
 *      own docblock) — translated 1:1 into a SanitizedProviderHttpException
 *      carrying only its category/statusCode.
 *   3. Any other \Throwable — the generic, defensive fallback for a
 *      raw client exception that somehow escapes ProviderRequestExecutor's
 *      own sanitization (Guzzle RequestException/ConnectionException, or
 *      anything else whose default getMessage() might embed
 *      request/response headers or body) — sanitized down to
 *      CATEGORY_UNKNOWN with no status code and, critically, the
 *      original message is NEVER read, logged, or forwarded anywhere by
 *      this method.
 */
final class OutboundProviderHttpClient
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function execute(Closure $operation, string $operationLabel): mixed
    {
        try {
            return $operation();
        } catch (InvalidPkceVerifierException|ExpiredAuthorizationCodeException|AuthorizationCodeAlreadyUsedException|OAuthAccountMismatchException|SanitizedProviderHttpException $e) {
            throw $e;
        } catch (SimulatedProviderFailureException $e) {
            // CHECKPOINT 8 addition (agent-8e-retry-backoff-ratelimit-design.md
            // §4): translate the raw, unparsed simulated Retry-After
            // value through RetryAfterParser HERE — the one enforcement
            // boundary — before it can ever reach a
            // SanitizedProviderHttpException. A malformed/malicious raw
            // value degrades to null (never throws, per that parser's
            // own never-throws contract), never leaks past this point
            // unclamped.
            $retryAfterSeconds = $e->retryAfterRaw() === null
                ? null
                : (new RetryAfterParser((int) config('integrations.outbox.max_backoff_seconds', 3600)))
                    ->parse($e->retryAfterRaw(), new DateTimeImmutable);

            throw new SanitizedProviderHttpException($e->category(), $e->statusCode(), $operationLabel, $retryAfterSeconds);
        } catch (LocalDomainFailureContract $e) {
            // CHECKPOINT 8.2 corrective pass. This boundary exists to
            // sanitize failures of request construction, transport, and
            // provider response handling. A LOCAL domain failure raised
            // inside the same closure is none of those, and folding it into
            // CATEGORY_UNKNOWN actively caused harm: callers read UNKNOWN as
            // "the provider's outcome is ambiguous", which is the one
            // classification that must never be auto-retried, so a definite
            // local conflict (a Gmail mailbox owned by another firm,
            // detected before the request was sent) parked the connection in
            // a reconciliation state that had no correct resolution.
            //
            // The marker's own contract requires such an exception to carry
            // no credential, token or payload, so rethrowing it unchanged
            // cannot leak anything this boundary would otherwise strip.
            throw $e;
        } catch (Throwable) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_UNKNOWN, null, $operationLabel);
        }
    }
}
