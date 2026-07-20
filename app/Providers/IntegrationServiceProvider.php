<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Policies\FirmIntegrationPolicy;
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
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(FirmIntegration::class, FirmIntegrationPolicy::class);
    }
}
