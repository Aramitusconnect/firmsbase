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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
