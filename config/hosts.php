<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical FirmsVault Application Surface Hostnames
    |--------------------------------------------------------------------------
    |
    | Mission 1 (canonical reconstruction) — Domain & Security Boundary
    | Architecture. Single source of truth for the canonical base URL of
    | every FirmsVault application surface. Nothing outside this file
    | should hardcode a firmsvault.com (or firmsvault.test) hostname —
    | Filament panel domain binding, the TrustHosts allow-list,
    | legacy-path redirects, and canonical link generation (password
    | resets, OAuth callbacks, webhook documentation) all resolve
    | through App\Services\CanonicalUrlService, which reads these six
    | values.
    |
    | Local/testing default to the *.firmsvault.test convention — no real
    | DNS resolution is required for either case: local development
    | resolves these via an /etc/hosts entry (developer convenience, not
    | automated here), and the test suite never performs a real network
    | lookup — Laravel's HTTP test client matches routes against the Host
    | header of an in-process request only.
    |
    | Staging/production MUST set every one of these explicitly via real
    | environment configuration — the defaults below are local-only and
    | are never appropriate for a deployed environment.
    |
    */

    'marketing_url' => env('MARKETING_URL', 'http://firmsvault.test'),

    'firm_app_url' => env('FIRM_APP_URL', 'http://app.firmsvault.test'),

    'client_portal_url' => env('CLIENT_PORTAL_URL', 'http://client.firmsvault.test'),

    'admin_url' => env('ADMIN_URL', 'http://admin.firmsvault.test'),

    'myattorney_url' => env('MYATTORNEY_URL', 'http://myattorney.firmsvault.test'),

    'api_url' => env('API_URL', 'http://api.firmsvault.test'),

];
