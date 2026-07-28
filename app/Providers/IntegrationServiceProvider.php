<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Policies\FirmIntegrationPolicy;
use Google\Auth\AccessToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * IntegrationServiceProvider — registers this mission's authorization
 * wiring for App\Integrations\.
 *
 * Registers Gate::policy(FirmIntegration::class, FirmIntegrationPolicy::class)
 * EXPLICITLY in boot(), deliberately NOT relying on Laravel's default
 * policy auto-discovery convention. Laravel's default discovery
 * convention (Illuminate\Auth\Access\Gate::guessPolicyName()) assumes a
 * model at `App\Models\{Model}` maps to a policy at
 * `App\Policies\{Model}Policy` (with a `Models\` segment additionally
 * stripped for models under `App\Models\`) — it has no special case for
 * `App\Integrations\Models\{Model}` mapping to
 * `App\Integrations\Policies\{Model}Policy`, so auto-discovery would
 * either silently fail to resolve this policy at all, or resolve to a
 * nonexistent `App\Policies\FirmIntegrationPolicy`. This is the exact
 * class of surprise already found and documented in Checkpoint 2 with
 * `Model::resolveFactoryName()` (checkpoint-00-final-specification.md
 * §6's STANDING CONVENTION) — the correct response here is the same:
 * be explicit, never assume framework auto-discovery correctly handles
 * a non-default namespace.
 *
 * FirmIntegrationPolicy is the FIRST standard Laravel Policy class
 * introduced anywhere in this codebase (checkpoint-00-final-specification.md
 * §17) — there is no existing AuthServiceProvider/policy-registration
 * convention to extend, so this is a new, dedicated provider rather
 * than an addition to app/Providers/AppServiceProvider.php.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * FirmsVault Live Integrations, Checkpoint 3 addition
     * (checkpoint3-combined-design.md §3; checkpoint3-security-review.md
     * Finding 2, required). Binds Google\Auth\AccessToken — the
     * maintained, Google-owned OIDC/ID-token verifier
     * GoogleWorkspaceProvider uses exclusively to verify the inbound
     * Gmail Pub/Sub push OIDC JWT (a real, attacker-reachable webhook
     * endpoint) — as a container singleton. This is the SOLE place
     * `new AccessToken(` may appear anywhere in this codebase:
     * GoogleWorkspaceProvider never constructs it inline, only ever
     * receives it constructor-promoted (identical shape to
     * Microsoft365Provider's own `private readonly
     * ProviderRequestExecutor $executor`), so it is swappable for a test
     * double (`app()->instance(AccessToken::class, $fakeAccessToken)`,
     * the same precedent already established by
     * ProviderConnectionServiceTenantMismatchTest/
     * ProviderConnectionServiceOAuthTest) in every test that exercises
     * Gmail webhook verification — no test run ever reaches Google's
     * real cert endpoint. `AccessToken::verify()`'s own internal
     * cert-fetch call is a disclosed, reviewed second real-network-call
     * site inside app/Integrations/ (distinct from
     * ProviderRequestExecutor, the one designated site
     * tests/Unit/Integrations/NoRealNetworkCallTest.php otherwise
     * enforces) — accepted because the target host is fixed inside this
     * trusted, maintained library, never attacker-influenced. See that
     * test file's own structural proof that GoogleWorkspaceProvider.php
     * never contains `new AccessToken(` and that this binding genuinely
     * exists.
     */
    public function register(): void
    {
        $this->app->singleton(AccessToken::class, fn () => new AccessToken);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(FirmIntegration::class, FirmIntegrationPolicy::class);
    }
}
