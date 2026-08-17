{{--
    Shared centred-card shell for the two public request forms, styled to sit
    next to the Filament login card rather than reproducing its internals.

    Self-contained CSS on purpose: these are the only unauthenticated pages
    outside a Filament panel, and coupling them to the build manifest bought
    nothing when the placeholder did it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }}</title>
    <style>
        :root { color-scheme: light dark; --bg:#f9fafb; --card:#fff; --fg:#030712; --muted:#4b5563;
                --line:#e5e7eb; --field:#fff; --primary:#2563eb; --primary-hover:#3b82f6; --err:#b91c1c; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#030712; --card:#111827; --fg:#fff; --muted:#9ca3af;
                    --line:rgba(255,255,255,.1); --field:#0b1220; --err:#f87171; }
        }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               background:var(--bg); color:var(--fg); padding:3rem 1.5rem;
               font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .wrap { width:100%; max-width:28rem; }
        .brand { text-align:center; font-weight:700; font-size:1.125rem; margin-bottom:1.5rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:.75rem;
                padding:2rem; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        h1 { font-size:1.5rem; line-height:2rem; font-weight:700; letter-spacing:-.025em; margin:0 0 .5rem; }
        .lede { margin:0 0 1.5rem; font-size:.875rem; line-height:1.4rem; color:var(--muted); }
        label { display:block; font-size:.875rem; font-weight:500; margin-bottom:.375rem; }
        input { width:100%; border:1px solid var(--line); border-radius:.5rem; background:var(--field);
                color:var(--fg); padding:.5rem .75rem; font-size:.875rem; margin-bottom:1rem; }
        input:focus { outline:2px solid var(--primary); outline-offset:0; border-color:transparent; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
        button { width:100%; border:0; border-radius:.5rem; background:var(--primary); color:#fff;
                 padding:.625rem 1rem; font-size:.875rem; font-weight:600; cursor:pointer; }
        button:hover { background:var(--primary-hover); }
        .foot { margin-top:1.5rem; text-align:center; font-size:.875rem; color:var(--muted); }
        .foot a { color:var(--primary); font-weight:600; text-decoration:none; }
        .err { color:var(--err); font-size:.8125rem; margin:-.75rem 0 .75rem; }
        .ok { border:1px solid var(--line); border-radius:.5rem; padding:1rem; font-size:.875rem;
              line-height:1.4rem; color:var(--muted); }
        .ok strong { display:block; color:var(--fg); font-size:1rem; margin-bottom:.375rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">FirmsBase</div>
        <div class="card">
            @if (session('requestReceived'))
                <h1>{{ __($receivedHeading ?? 'Request received') }}</h1>
                <div class="ok">
                    <strong>{{ __('Thanks — we have your details.') }}</strong>
                    {{ $receivedBody }}
                </div>
            @else
                <h1>{{ $heading }}</h1>
                <p class="lede">{{ $lede }}</p>

                @if ($errors->any())
                    <div class="err">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ $action }}">
                    @csrf
                    @yield('fields')
                    <button type="submit">{{ $submitLabel }}</button>
                </form>
            @endif

            <div class="foot">
                {{ __('Already have an account?') }}
                <a href="{{ $loginUrl }}">{{ __('Sign in') }}</a>
            </div>
        </div>
    </div>
</body>
</html>
