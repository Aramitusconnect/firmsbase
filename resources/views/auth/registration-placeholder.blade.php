{{--
    Deliberate placeholder, not a registration form.

    There is no canonical self-registration backend in this release — the panels
    do not enable Filament's ->registration(), and no register route existed on
    any host. Rendering input fields here would imply an account can be created,
    which it cannot; the honest thing is an entry point that says what actually
    happens next.

    Firms are provisioned through FirmProvisioningService (firms:provision /
    ProvisionFirmAction) and clients through ClientPortalService's invitation
    flow. Wiring public signup to either is a real onboarding project with
    consent, verification and anti-abuse requirements — out of scope for a UI
    entry point.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }}</title>
    {{--
        Intentionally self-contained rather than @vite(...): this page must
        render identically under test and at runtime, and a build-manifest
        dependency would couple a static placeholder to the asset pipeline for
        no benefit. It is replaced wholesale when real registration is built.
    --}}
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100%; display: flex; align-items: center; justify-content: center;
               background: #f9fafb; color: #030712; padding: 3rem 1.5rem;
               font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { width: 100%; max-width: 28rem; text-align: center; }
        h1 { font-size: 1.5rem; line-height: 2rem; font-weight: 700; letter-spacing: -0.025em; margin: 0; }
        p { margin: 1rem 0 0; font-size: 0.875rem; line-height: 1.5rem; color: #4b5563; }
        a.back { display: inline-flex; align-items: center; justify-content: center; margin-top: 2rem;
                 border-radius: 0.5rem; background: #2563eb; color: #fff; padding: 0.5rem 1rem;
                 font-size: 0.875rem; font-weight: 600; text-decoration: none; }
        a.back:hover { background: #3b82f6; }
        @media (prefers-color-scheme: dark) {
            body { background: #030712; color: #fff; }
            p { color: #9ca3af; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $heading }}</h1>
        <p>{{ $body }}</p>
        <a class="back" href="{{ $loginUrl }}">{{ __('Back to sign in') }}</a>
    </div>
</body>
</html>
