<?php

use App\Http\Controllers\ReadinessController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Registered the same way Laravel's own `health: '/up'` liveness
            // route is registered — outside the `web` middleware group, so
            // an ECS/ALB readiness probe doesn't pay for session/cookie/CSRF
            // overhead on every check. See app/Http/Controllers/ReadinessController.php
            // and docs/ecs/container-architecture.md "Health checks".
            Route::get('/readyz', ReadinessController::class);

            // Registers the Checkpoint 7 inbound webhook route file outside
            // any implicit middleware group, consistent with the /readyz
            // registration above — see routes/webhooks.php for why.
            require __DIR__.'/../routes/webhooks.php';
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        // CHECKPOINT 8 — resolves checkpoint-00 §13/§23's frozen,
        // explicit "no scheduler mechanism exists anywhere in this
        // codebase" dependency NARROWLY
        // (agent-8h-architecture-security-review.md §1 item 1; §2 item
        // 1). Every command below is a plain, cheap, non-tenant Artisan
        // command (never a ShouldQueue job itself) that enumerates
        // active firms from the non-RLS `firms` table and dispatches
        // one per-firm queued job each — see each command's own
        // docblock. Actually RUNNING Laravel's scheduler
        // (`schedule:work` or a cron/systemd-timer entry invoking
        // `schedule:run` every minute) in every environment is a
        // disclosed, non-blocking OPERATIONAL dependency this
        // application-code change cannot itself satisfy — out of scope
        // for this code-only mission per checkpoint-00 §19's
        // environment restrictions, and must be handed to the eventual
        // deployment/ops owner as a documented requirement.
        $schedule->command('integrations:outbox:dispatch')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('integrations:sync:retry-poll')
            ->everyThreeMinutes()
            ->withoutOverlapping();

        $schedule->command('integrations:retention:sweep')
            ->daily()
            ->withoutOverlapping();

        // CHECKPOINT 11 — SuperAdmin cross-firm integration oversight
        // (reviews/checkpoint-11/frozen-design-post-security-review.md
        // §5). Same shape as the three commands above: a plain, cheap,
        // non-tenant Artisan command that enumerates active firms from
        // the non-RLS `firms` table and dispatches one per-firm queued
        // job each, refreshing the no-RLS
        // `integration_platform_overview_summaries` snapshot table the
        // platform-admin overview page reads.
        $schedule->command('integrations:platform-overview:refresh')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Phase 2 — FirmsVault Platform Admin Control Center
        // ("Integration Operations Center"). Same shape as the
        // platform-overview refresh immediately above: a plain, cheap,
        // non-tenant Artisan command that enumerates the non-RLS
        // `integration_providers` table and dispatches one per-provider
        // queued job each, refreshing the no-RLS
        // `integration_platform_provider_health_summaries` snapshot
        // table the platform-admin Provider Health view reads.
        $schedule->command('integrations:platform-provider-health:refresh')
            ->everyFiveMinutes()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the AWS ALB in front of this container for exactly the
        // header set AWS ELB/ALB actually sends: X-Forwarded-For,
        // X-Forwarded-Port, X-Forwarded-Proto — deliberately NOT
        // X-Forwarded-Host (AWS ALB never sends it; see Symfony's own
        // comment on Request::HEADER_X_FORWARDED_AWS_ELB). This is what
        // lets Request::isSecure()/url()/route() correctly report "https"
        // once the ALB terminates TLS in front of this always-plain-HTTP
        // container (docker/web/Caddyfile disables auto_https — TLS never
        // terminates here).
        //
        // Proxy address policy — reviewed, not a framework default. Two
        // DIFFERENT layers of protection are in play here; do not conflate
        // them:
        //
        // 1. Application layer (what `at: '*'` actually does): Laravel
        //    trusts the AWS-ELB header set — including X-Forwarded-Proto —
        //    from ANY source IP that reaches this app over HTTP. This does
        //    NOT prevent a direct, unauthorized HTTP client from setting
        //    X-Forwarded-Proto: https and being believed; Laravel has no
        //    way to distinguish that from genuine ALB traffic once `at` is
        //    '*'. The one header this app never trusts regardless of `at`
        //    is X-Forwarded-Host (excluded from
        //    Request::HEADER_X_FORWARDED_AWS_ELB), which is the
        //    independent, header-scoped defense against host-spoofing —
        //    see tests/Feature/Http/TrustedProxyTest.php.
        //
        // 2. Network layer (what actually makes `at: '*'` safe here): the
        //    ECS task security group (sg-0db14e50ea5c5466c) is intended to
        //    permit inbound :8080 exclusively from the ALB's own security
        //    group — never from the public internet or any other ENI in
        //    the VPC — so an unauthorized client can never reach this
        //    container to exploit point 1 in the first place. PHPUnit has
        //    no network layer and CANNOT prove this boundary; it must be
        //    verified LIVE against the real AWS security-group
        //    configuration before the web service is launched. Fargate
        //    targets have no fixed, publishable IP range to pin to instead
        //    of `at: '*'` (task ENIs are dynamic), so IP-list trust inside
        //    the app would add ongoing maintenance risk without additional
        //    security value the security group doesn't already provide. If
        //    that security-group rule is ever loosened to a CIDR-based
        //    rule instead of an SG-reference rule, `at: '*'` must be
        //    revisited alongside it.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
