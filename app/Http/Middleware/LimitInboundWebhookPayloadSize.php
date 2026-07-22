<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LimitInboundWebhookPayloadSize — Checkpoint 7
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §2).
 * 256 KB request-size limit for the inbound webhook route, enforced by
 * measuring the ACTUAL received byte count — never a trusted
 * `Content-Length` header alone (guards against
 * transfer-encoding/chunking ambiguity). Checked BEFORE signature
 * verification and before any database write, per this route's
 * middleware ordering (see routes/webhooks.php).
 *
 * `$request->getContent()` has, by the time ANY middleware runs,
 * already fully buffered the actual request body bytes (Symfony's
 * `Request::getContent()` reads and caches `php://input` in full) —
 * `strlen()` of that value reflects what was genuinely received, not
 * merely what the client claimed via `Content-Length`. Reverse-proxy
 * (`client_max_body_size`) and PHP-FPM (`post_max_size`) layers are
 * legitimate defense-in-depth but are deployment configuration outside
 * this repository's scope (frozen design §2) — tracked as a
 * pre-production-deployment checklist item, not a Checkpoint 7 code
 * gate.
 *
 * Response shape (frozen design §8.1 row 11): `413`,
 * `{"status":"rejected","reason":"payload_too_large"}` — safe to
 * disclose since it fires identically regardless of any other guess
 * dimension (provider validity, token validity, signature validity).
 */
class LimitInboundWebhookPayloadSize
{
    private const MAX_BYTES = 256 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['status' => 'rejected', 'reason' => 'payload_too_large'], 413);
        }

        return $next($request);
    }
}
