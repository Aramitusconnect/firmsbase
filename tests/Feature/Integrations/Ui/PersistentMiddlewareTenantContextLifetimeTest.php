<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishFirmTenantContext;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SCRATCH INVESTIGATION TEST — Checkpoint 10 follow-up, "does the
 * AppServiceProvider persistent-middleware fix actually work for real
 * production traffic" question.
 *
 * This does NOT use Livewire::test() at all — it directly reproduces
 * the EXACT mechanism Livewire\Mechanisms\PersistentMiddleware\
 * PersistentMiddleware::applyPersistentMiddleware() uses to replay
 * middleware (see Livewire\Drawer\Utils::applyMiddleware(), vendor/
 * livewire/livewire/src/Drawer/Utils.php:177-191):
 *
 *   (new \Illuminate\Pipeline\Pipeline(app()))
 *       ->send($request)
 *       ->through($middleware)
 *       ->then(fn () => new \Illuminate\Http\Response());
 *
 * i.e. a ONE-OFF pipeline whose terminal `$next` closure is an empty
 * dummy response — NOT a continuation into the rest of the real
 * Livewire request lifecycle (hydrateProperties() / the mounted
 * action's own closure / dehydrate()). This test asks: given that
 * shape, does tenant context established by EstablishFirmTenantContext/
 * ApplyTenantDatabaseContext survive past the call to
 * Utils::applyMiddleware(), i.e. is it still active by the time
 * Livewire's own `fromSnapshot()` moves on to hydrateProperties()
 * (which is where ModelSynth::hydrate() re-fetches the FORCE-RLS
 * protected #[Locked] $record property)?
 *
 * FINDING: NO. Both middleware wrap their entire effect in
 * `try { return $next($request); } finally { clear...(); } `. Because
 * $next() here terminates immediately in the dummy Response — it is
 * NOT the rest of the real request — the finally block fires
 * synchronously, within the SAME call to Utils::applyMiddleware(),
 * before Livewire's 'snapshot-verified' listener even returns. So by
 * the time control returns to HandleComponents::fromSnapshot() and
 * proceeds to hydrateProperties(), tenant context (both PHP-memory and
 * the PostgreSQL app.current_firm_id session setting) has already been
 * torn down again — even though the middleware genuinely ran, and even
 * setting aside the separate isLivewireRoute()/Livewire::test() quirk
 * the coordinator found. This is a structural mismatch between how
 * these two middleware classes are written (self-cleaning around
 * $next(), correct for a REAL middleware chain that continues into the
 * actual response) and how Livewire's persistent-middleware replay
 * mechanism uses middleware (fire a request through a disposable
 * pipeline purely for side effects that are expected to persist past
 * $next(), e.g. Auth::setUser() — never side effects meant to be
 * undone once $next() returns).
 */
final class PersistentMiddlewareTenantContextLifetimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_set_by_the_middleware_does_not_survive_past_the_persistent_middleware_replay_pipeline(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );

        $this->actingAs($firmUser->user);

        // Sanity: no tenant context active before we start (fresh test).
        $this->assertNoDatabaseTenantContext('Expected clean slate before the probe.');
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());

        $request = Request::create('/firm/firm-integrations/deadbeef', 'GET');
        $request->setUserResolver(fn () => $firmUser->user);
        // Bind a real session so EstablishFirmTenantContext (which reads
        // $request->user(), not the container's Auth facade state) sees
        // the same authenticated user Livewire's fake request would.
        $request->setLaravelSession($this->app['session']->driver());

        $sawContextDuringNext = null;
        $sawDatabaseContextDuringNext = null;

        // EXACT shape of Livewire\Drawer\Utils::applyMiddleware().
        $response = (new Pipeline(app()))
            ->send($request)
            ->through([
                EstablishFirmTenantContext::class,
                ApplyTenantDatabaseContext::class,
            ])
            ->then(function () use (&$sawContextDuringNext, &$sawDatabaseContextDuringNext) {
                // This closure stands in for "the rest of the real
                // request" from the middleware's point of view. Under
                // Livewire's actual persistent-middleware mechanism,
                // THIS is where the dummy Response is created and the
                // pipeline immediately starts unwinding — it is NOT
                // where hydrateProperties()/the mounted action would
                // actually run in a real request.
                $sawContextDuringNext = app(TenantContextService::class)->hasFirmContext();
                $sawDatabaseContextDuringNext = DB::selectOne(
                    "select current_setting('app.current_firm_id', true) as value"
                )->value;

                return new Response;
            });

        // Proves the middleware genuinely ran and genuinely established
        // context while $next() was executing (rules out "middleware
        // never invoked" as the explanation for what follows).
        $this->assertTrue(
            $sawContextDuringNext,
            'Expected EstablishFirmTenantContext to have activated PHP-memory firm context during $next().'
        );
        $this->assertSame(
            (string) $firm->id,
            $sawDatabaseContextDuringNext,
            'Expected ApplyTenantDatabaseContext to have pushed app.current_firm_id during $next().'
        );

        // THE ACTUAL QUESTION: is context still active once the
        // pipeline call has fully returned — i.e. at the exact point
        // Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::
        // applyPersistentMiddleware() returns control back to
        // HandleComponents::fromSnapshot(), which then calls
        // hydrateProperties()?
        $this->assertFalse(
            app(TenantContextService::class)->hasFirmContext(),
            'THIS IS THE BUG: if this fails (context is still active), the fix works. '
            .'As implemented, EstablishFirmTenantContext clears PHP-memory context in a '
            .'finally block around $next(), and $next() here is a disposable dummy '
            .'response terminator, not a continuation into the rest of the real request — '
            .'so context is torn down before Livewire ever reaches hydrateProperties().'
        );

        $this->assertNoDatabaseTenantContext(
            'THIS IS THE BUG: if this fails (database context is still set), the fix works. '
            .'ApplyTenantDatabaseContext clears app.current_firm_id in a finally block '
            .'around the same disposable $next(), for the same reason as above.'
        );
    }
}
