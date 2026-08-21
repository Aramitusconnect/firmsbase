{{--
    Shown when a visitor trips a rate limit. Replaces Symfony's bare
    "429 Too Many Requests", which reads to a member of the public as though
    the site is broken or has rejected them personally.

    Deliberately plain and self-contained: this renders on the public
    MyAttorney host and inside the authenticated panels, so it borrows no
    layout and loads no assets — a page shown when a visitor is already being
    told to slow down should not itself issue more requests.
--}}
@php
    $retryAfter = (int) ($exception?->getHeaders()['Retry-After'] ?? 0);
    $wait = $retryAfter > 60
        ? ceil($retryAfter / 60).' minute'.(ceil($retryAfter / 60) === 1.0 ? '' : 's')
        : max($retryAfter, 1).' second'.($retryAfter === 1 ? '' : 's');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @if ($retryAfter > 0)
        <meta http-equiv="refresh" content="{{ $retryAfter }}">
    @endif
    <title>One moment please</title>
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               background:#f4f5f7; color:#1f2937; padding:32px 16px;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .card { max-width:460px; background:#fff; border-radius:12px; padding:32px 28px;
                box-shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06); }
        h1 { font-size:1.25rem; margin:0 0 12px; }
        p { line-height:1.55; margin:0 0 12px; }
        .muted { color:#6b7280; font-size:.9rem; margin-bottom:0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>One moment please</h1>
        <p>We received several requests from your connection in a short space of time, so we have paused briefly to keep things running smoothly for everyone.</p>
        <p><strong>Please try again in about {{ $wait }}.</strong>@if ($retryAfter > 0) This page will refresh itself.@endif</p>
        <p class="muted">Nothing you entered has been lost, and nothing is wrong with your request. If this keeps happening, please contact the firm directly.</p>
    </div>
</body>
</html>
