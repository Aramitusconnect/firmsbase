<?php

return [

    /*
    |--------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------
    |
    | Mission 1B (Extreme Security Hardening), sections 19-20. Real
    | script/style/resource origins in this application, inventoried
    | directly from the codebase rather than guessed:
    |
    |   - Livewire/Filament/Alpine.js: same-origin bundled assets, plus
    |     Alpine's expression evaluation (used pervasively by Filament's
    |     x-data/x-show throughout its UI) requires 'unsafe-eval' —
    |     Filament does not ship the CSP-safe Alpine build. Livewire's
    |     own injected inline <script>/<style> blocks are nonce-scoped
    |     via Illuminate\Support\Facades\Vite::useCspNonce(), which
    |     Livewire's FrontendAssets reads automatically
    |     (see vendor/livewire/livewire/src/Mechanisms/FrontendAssets).
    |   - Plaid Link (resources/views/filament-client-portal/plaid-link.blade.php)
    |     loads https://cdn.plaid.com/link/v2/stable/link-initialize.js
    |     and opens its own hosted iframe from the same origin.
    |   - TOTP QR codes (Filament's own SetUpAppAuthenticationAction)
    |     render as a data: URI image — no external image host is
    |     currently embedded anywhere (document downloads are not yet a
    |     public/CloudFront URL surface — see this mission's file-
    |     security findings; when one is added, its exact origin must be
    |     added here, never a wildcard).
    |   - Google Workspace / Microsoft 365 OAuth: full-page server-side
    |     redirects (302), never a client-embedded script/frame — CSP's
    |     script-src/frame-src do not govern top-level navigation, so
    |     these origins deliberately do not appear here.
    |   - No analytics/error-tracking script is configured anywhere in
    |     this application (confirmed by this mission's audit) — nothing
    |     to allow-list for that.
    |
    | report_only defaults to true: this mission's own guidance is to
    | "prefer staged/report-only rollout for CSP if necessary" and to
    | "not break Filament/Livewire by blindly deploying CSP." This
    | environment has no real browser available to validate the policy
    | against, so it ships enforcing nothing yet, only reporting
    | violations — an operator with real browser access should review
    | report_uri's violation reports before flipping SECURITY_CSP_REPORT_ONLY
    | to false in a real environment.
    |
    */

    'csp' => [
        'enabled' => env('SECURITY_CSP_ENABLED', true),
        'report_only' => env('SECURITY_CSP_REPORT_ONLY', true),
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),

        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-eval'", 'https://cdn.plaid.com'],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", 'https://cdn.plaid.com'],
            'frame-src' => ['https://cdn.plaid.com'],
            'frame-ancestors' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ],
    ],

];
