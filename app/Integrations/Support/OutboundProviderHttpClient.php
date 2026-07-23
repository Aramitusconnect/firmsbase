<?php

declare(strict_types=1);

namespace App\Integrations\Support;

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
 * (`Illuminate\Support\Facades\Http`, GuzzleHttp, curl_*, etc.) — this
 * is what keeps tests/Unit/Integrations/NoRealNetworkCallTest.php's
 * whole-tree scan green, and is not a shortcut: Checkpoint 5's only
 * caller is TestProvider's simulated call paths, which themselves make
 * zero real network calls by design (checkpoint-00-final-specification.md
 * §18/§21) — there is nothing for this checkpoint to genuinely transport
 * over HTTP yet. This class's real job today is structural: it is the
 * fixed shape a FUTURE live-provider checkpoint's `Http::timeout()->...`
 * call sites are required to be wrapped by, passed in here as the
 * $operation closure — execute() never knows or cares whether that
 * closure did real I/O or purely in-memory simulation, only how to
 * sanitize whatever it throws.
 *
 * Catches, in order:
 *   1. Exception types this codebase ALREADY defines to be safe,
 *      app-level, non-leaking (never raw provider response text) —
 *      rethrown completely unchanged, since sanitizing them further
 *      would only destroy information a caller legitimately needs
 *      (e.g. ProviderConnectionService branching on
 *      InvalidPkceVerifierException vs OAuthAccountMismatchException).
 *   2. SimulatedProviderFailureException — TestProvider's stand-in for
 *      "a real provider's outbound call failed" (see that exception's
 *      own docblock) — translated 1:1 into a SanitizedProviderHttpException
 *      carrying only its category/statusCode.
 *   3. Any other \Throwable — the generic, defensive fallback for a
 *      future live provider's raw client exception (Guzzle
 *      RequestException/ConnectionException, or anything else whose
 *      default getMessage() might embed request/response headers or
 *      body) — sanitized down to CATEGORY_UNKNOWN with no status code
 *      and, critically, the original message is NEVER read, logged, or
 *      forwarded anywhere by this method.
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
        } catch (InvalidPkceVerifierException|ExpiredAuthorizationCodeException|AuthorizationCodeAlreadyUsedException|OAuthAccountMismatchException $e) {
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
                    ->parse($e->retryAfterRaw(), new DateTimeImmutable());

            throw new SanitizedProviderHttpException($e->category(), $e->statusCode(), $operationLabel, $retryAfterSeconds);
        } catch (Throwable) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_UNKNOWN, null, $operationLabel);
        }
    }
}
