<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * AddSecurityHeaders — Mission 1 (canonical reconstruction), Domain &
 * Security Boundary Architecture, section 37. Baseline security
 * headers only — deliberately NOT a Content-Security-Policy
 * (permissive or otherwise): "full CSP/extreme hardening belongs to
 * Mission 1B," and getting CSP wrong (e.g. blocking Filament's own
 * inline styles/Livewire scripts) risks breaking the application, so a
 * real CSP is left to that dedicated mission rather than shipped
 * half-considered here. No existing security-headers middleware exists
 * on the canonical branch to preserve or conflict with (confirmed by
 * this mission's own audit).
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

        return $response;
    }
}
