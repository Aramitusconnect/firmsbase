<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Per-concern rate limiters for the public MyAttorney host.
 *
 * These exist because an inline `throttle:5,1` does NOT give a route its own
 * budget. ThrottleRequests::resolveRequestSignature() keys on
 * sha1($route->getDomain().'|'.$request->ip()) — the URI is not part of it —
 * so every anonymous request to this host shares ONE counter, and each route
 * merely tests that shared counter against its own maximum. The effective
 * limit for the whole host therefore collapses to the strictest route the
 * visitor happens to reach.
 *
 * In practice a prospect who simply read a firm's profile a few times was
 * refused at the first click of "Start Secure Intake", because those page
 * views had already spent the intake budget. Reproduced on staging: five
 * profile GETs, then 429 on the first POST.
 *
 * A NAMED limiter keys on md5($limiterName.$key) (see
 * handleRequestUsingNamedLimiter), so each concern below gets a genuinely
 * separate bucket. The limits are unchanged from what the routes already
 * declared, with one deliberate exception noted on the intake-start limiter.
 *
 * Every limiter is keyed by client IP. That is the only signal available on a
 * mostly-cookieless public surface, and it is the real client IP rather than
 * the load balancer's: bootstrap/app.php trusts the ALB via
 * HEADER_X_FORWARDED_AWS_ELB, so one visitor can never throttle another.
 */
class MyAttorneyRateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Public reads: profile, home, attorney pages.
        RateLimiter::for('myattorney-public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Crawler surface — robots.txt and sitemaps, deliberately generous.
        RateLimiter::for('myattorney-crawl', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // "What do you need help with?" — a read, but a small one.
        RateLimiter::for('myattorney-intake-choose', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Starting an intake creates state, so it stays the tightest limit
        // here. Raised from 5 to 10 because the flow now legitimately costs
        // TWO posts — one that lands on the practice-area chooser and one
        // carrying the choice — and a visitor who changes their mind spends
        // two more. Five made an ordinary, honest journey fail.
        RateLimiter::for('myattorney-intake-start', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Returning to a resumable link. Generous: a prospect may reload,
        // navigate back, or resume across several sittings.
        RateLimiter::for('myattorney-intake-resume', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Attaching evidence — several files in a row is normal.
        RateLimiter::for('myattorney-intake-documents', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Correction reports: reading the form, and submitting it.
        RateLimiter::for('myattorney-correction', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('myattorney-correction-submit', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
