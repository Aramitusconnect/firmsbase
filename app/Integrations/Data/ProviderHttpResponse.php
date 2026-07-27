<?php

declare(strict_types=1);

namespace App\Integrations\Data;

/**
 * ProviderHttpResponse — the ONLY shape `App\Integrations\Support\ProviderRequestExecutor::send()`
 * returns on success (checkpoint1-design-http-ratelimit-usage.md §2.3).
 * A small, closed, constructor-validated value object — NEVER the raw
 * `Illuminate\Http\Client\Response` object — so a provider adapter can
 * never accidentally forward the raw response, with its potentially
 * sensitive headers, further up the call stack.
 *
 * `$headers` is an EXPLICIT ALLOWLIST subset only, copied in by the
 * executor from the real response — never a blind `$response->headers()`
 * passthrough. This is what keeps an `Authorization`/`Set-Cookie`-shaped
 * response header from ever leaking into a provider adapter's return
 * value or, downstream, into a TimelineEventRecorder metadata array or
 * the usage-record metadata bag.
 */
final class ProviderHttpResponse
{
    /**
     * @param  array<string, string>  $headers  already-allowlisted subset only
     */
    public function __construct(
        public readonly int $status,
        public readonly array $json,
        private readonly array $headers,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
}
