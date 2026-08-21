<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * ForceRlsRouteBindingGuardrailTest — Follow-up 4 (FORCE-RLS
 * Route-Binding Guardrail). Permanent regression guard for the rule
 * documented in docs/security/force-rls-route-binding-guardrail.md: no
 * plain route (routes/web.php, routes/webhooks.php) may declare an
 * implicitly-bound Eloquent-model route parameter (an
 * `App\Models\*`/`App\Marketplace\Models\*`-typed controller-method
 * parameter matching a route segment, resolved by Laravel's
 * `SubstituteBindings` middleware) for a table that carries FORCE ROW
 * LEVEL SECURITY.
 *
 * Root cause this guards against: this app's global middleware-
 * priority order (bootstrap/app.php, frozen — reordering it is out of
 * scope for this guardrail) always runs `SubstituteBindings` ahead of
 * any custom tenant-context middleware, so an implicit binding on a
 * FORCE-RLS table would always resolve the row before
 * `app.current_firm_id` is set and would always see zero rows under
 * FORCE RLS — a route that can never work, and a trap for whoever
 * "fixes" it next by weakening tenant context or the RLS policy
 * instead of simply not implicitly binding.
 *
 * Mechanism: reflects every currently-registered route (the booted
 * `Route` facade already contains everything `routes/web.php` and
 * `routes/webhooks.php` register — see bootstrap/app.php's
 * `withRouting()`/`require` wiring), filtered down to application-
 * owned, non-Filament actions (see the loop below for why that filter
 * is safe), and inspects each one's parameters for a genuine implicit
 * binding via `Route::signatureParameters(UrlRoutable::class)` — the
 * exact same primitive Laravel's own `SubstituteBindings` middleware
 * uses internally to decide what to bind, not a hand-rolled parser.
 * Whether a bound model's table is "FORCE-RLS" is answered by
 * `RowLevelSecurityCoverageMappingService::isForced()`/`forcedTables()`
 * — the one canonical, already-trusted FORCE-RLS registry (derived
 * from the real `*_force_rls_on_*_table.php` migrations, not a second
 * hardcoded list) — so this test can never drift from what the rest of
 * the RLS rollout considers FORCE-RLS.
 */
final class ForceRlsRouteBindingGuardrailTest extends TestCase
{
    public function test_no_plain_route_implicitly_binds_a_force_rls_table_model(): void
    {
        $service = app(RowLevelSecurityCoverageMappingService::class);
        $forcedTables = $service->forcedTables();

        $this->assertNotEmpty(
            $forcedTables,
            'Sanity check: the FORCE-RLS registry reports zero forced tables — this guardrail would trivially pass for the wrong reason. Investigate RowLevelSecurityCoverageMappingService::forcedTables() before trusting the result below.'
        );

        $scannedRouteCount = 0;
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $action = ltrim($route->getActionName(), '\\');

            // Only application-owned actions, and never a Filament
            // panel one. Filament panel routes are excluded entirely:
            // every Filament resource Page::mount() signature is
            // `int|string $record` (vendor/filament/filament/src/
            // Resources/Pages/{ViewRecord,EditRecord,
            // ManageRelatedRecords}.php — confirmed by direct source
            // read for this guardrail), never a typed Eloquent
            // parameter, so Filament's own record resolution never
            // goes through `SubstituteBindings`/implicit binding at
            // all — reflecting on it would produce noise, not signal.
            // Vendor/framework routes (anything not under `App\`) and
            // bare Closures are excluded the same way — neither can
            // carry an `App\Models\*`-typed parameter, so there is
            // nothing to check.
            if (! str_starts_with($action, 'App\\') || str_starts_with($action, 'App\\Filament\\')) {
                continue;
            }

            $scannedRouteCount++;

            foreach ($route->signatureParameters(UrlRoutable::class) as $parameter) {
                $type = $parameter->getType();

                // signatureParameters(UrlRoutable::class) already
                // guarantees a non-builtin type implementing
                // UrlRoutable; the instanceof/isBuiltin guards below
                // are defensive, not load-bearing.
                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $modelClass = $type->getName();

                if (! is_subclass_of($modelClass, Model::class)) {
                    continue;
                }

                // Scope to this app's own tenant-owned model
                // namespaces (per the mission brief) — a UrlRoutable
                // implementation outside these namespaces is not a
                // FORCE-RLS tenant model this guardrail is concerned
                // with.
                if (! str_starts_with($modelClass, 'App\\Models\\') && ! str_starts_with($modelClass, 'App\\Marketplace\\Models\\')) {
                    continue;
                }

                $table = (new $modelClass)->getTable();

                if ($service->isForced($table)) {
                    $violations[] = sprintf(
                        '[%s] %s -> %s (parameter $%s type-hinted %s, table `%s` carries FORCE ROW LEVEL SECURITY)',
                        implode('|', $route->methods()),
                        $route->uri(),
                        $action,
                        $parameter->getName(),
                        $modelClass,
                        $table,
                    );
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $scannedRouteCount,
            'Sanity check: this guardrail scanned zero application routes — the filter above is almost certainly broken (not that the app genuinely has no App\\-owned routes). See routes/web.php and routes/webhooks.php.'
        );

        $this->assertSame(
            [],
            $violations,
            "Found implicit Eloquent route-model binding(s) against FORCE-RLS table(s) on a plain route:\n"
            .implode("\n", $violations)
            ."\n\nSee docs/security/force-rls-route-binding-guardrail.md. Replace the typed model parameter with a plain string/int identifier, and resolve the model manually inside TenantContextService::runWithFirmContext() (or an equivalent narrow self-lookup context) AFTER establishing tenant context from the authenticated actor — matching App\\Http\\Controllers\\Firm\\DocumentDownloadController and App\\Http\\Controllers\\ClientPortal\\DocumentDownloadController."
        );
    }

    /**
     * Regression pin, in addition to the general scan above: proves
     * the guardrail's own filter genuinely reaches the two known
     * document-download routes (Firm and Client Portal — both on the
     * FORCE-RLS `documents` table) and that both still take zero
     * UrlRoutable-typed parameters. This exists so a future filter
     * bug that accidentally excludes every route (making the general
     * assertion above vacuously pass with `$violations === []` for
     * the wrong reason) cannot go unnoticed — this test fails loudly
     * instead.
     */
    public function test_document_download_routes_stay_on_the_plain_string_identifier_pattern(): void
    {
        $expectedActions = [
            'App\\Http\\Controllers\\Firm\\DocumentDownloadController@show',
            'App\\Http\\Controllers\\ClientPortal\\DocumentDownloadController@show',
        ];

        $found = [];

        foreach (Route::getRoutes() as $route) {
            $action = ltrim($route->getActionName(), '\\');

            if (! in_array($action, $expectedActions, true)) {
                continue;
            }

            $found[] = $action;

            $this->assertSame(
                [],
                $route->signatureParameters(UrlRoutable::class),
                "{$action} must take zero UrlRoutable-typed parameters — its {document} route segment must stay a plain string uuid, resolved manually inside tenant context, never an implicit Eloquent binding."
            );
        }

        $this->assertSame(
            $expectedActions,
            array_values(array_intersect($expectedActions, $found)),
            'Expected both the Firm and Client Portal document-download routes to be registered — if either has been renamed/removed, update this pin deliberately rather than letting it silently stop checking anything.'
        );
    }
}
