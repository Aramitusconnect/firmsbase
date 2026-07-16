<?php

use App\Http\Controllers\ReadinessController;
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
        },
    )
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
        // Proxy address policy — reviewed, not a framework default:
        // `at: '*'` trusts every source IP for this specific header set.
        // That is safe ONLY because the real security boundary is the ECS
        // task security group (sg-0db14e50ea5c5466c), which permits
        // inbound :8080 exclusively from the ALB's own security group —
        // never from the public internet or any other ENI in the VPC.
        // Fargate targets have no fixed, publishable IP range to pin to
        // instead (task ENIs are dynamic), so IP-list trust here would add
        // ongoing maintenance risk without additional security value the
        // security group doesn't already provide. If that security-group
        // rule is ever loosened to a CIDR-based rule instead of an
        // SG-reference rule, this trust-everything header policy must be
        // revisited alongside it. Excluding X-Forwarded-Host from the
        // trusted header set is the independent, header-scoped defense
        // against host-spoofing that holds regardless of the IP policy —
        // see tests/Feature/Http/TrustedProxyTest.php.
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
