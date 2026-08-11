<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * AddSecurityHeaders — Mission 1 (canonical reconstruction), Domain &
 * Security Boundary Architecture, section 37, extended by Mission 1B
 * (Extreme Security Hardening), sections 19-20 to add a real
 * Content-Security-Policy. Mission 1 deliberately shipped only the
 * baseline headers below and left CSP to this dedicated mission — see
 * config/security_headers.php for the full origin inventory and the
 * report-only-by-default rationale.
 *
 * Registered as a global middleware (bootstrap/app.php's
 * $middleware->append()) rather than duplicated across routes/web.php
 * and all three Filament panels' own ->middleware() arrays — Filament
 * panel routes do not run through Laravel's `web` middleware group, so
 * this had to be either genuinely global or repeated three times;
 * global is the one that can never silently drift out of sync.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $nonce = null;

        // Must run BEFORE $next($request) so Blade/Livewire pick up the
        // nonce while rendering — Livewire's own FrontendAssets reads
        // Vite::cspNonce() internally to nonce-scope its injected
        // inline <script>/<style> blocks (see config/security_headers.php).
        if (config('security_headers.csp.enabled')) {
            $nonce = Vite::useCspNonce();
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Browsers only honor Strict-Transport-Security when the response
        // was actually delivered over HTTPS (per the HSTS spec) — safe to
        // set unconditionally, including in local/testing over plain
        // HTTP, where it is simply ignored.
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        if (config('security_headers.csp.enabled')) {
            $headerName = config('security_headers.csp.report_only')
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($headerName, $this->buildCspHeaderValue($nonce));
        }

        return $response;
    }

    private function buildCspHeaderValue(?string $nonce): string
    {
        $directives = config('security_headers.csp.directives', []);
        $reportUri = config('security_headers.csp.report_uri');

        $parts = [];

        foreach ($directives as $directive => $sources) {
            if ($directive === 'script-src' && $nonce !== null) {
                $sources[] = "'nonce-{$nonce}'";
            }

            $parts[] = trim($directive.' '.implode(' ', $sources));
        }

        if (is_string($reportUri) && $reportUri !== '') {
            $parts[] = "report-uri {$reportUri}";
        }

        return implode('; ', $parts);
    }
}
