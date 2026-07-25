<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Policies\FirmPolicy;
use App\Policies\FirmUserPolicy;
use App\Policies\PlatformAdminPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * PlatformAdminPolicyServiceProvider — Phase 1 FirmsVault Admin Control
 * Center. Registers Gate::policy(Firm::class, FirmPolicy::class) and
 * Gate::policy(FirmUser::class, FirmUserPolicy::class) explicitly in
 * boot(), mirroring IntegrationServiceProvider's existing precedent of
 * being explicit rather than relying on Laravel's default policy
 * auto-discovery convention — even though, unlike IntegrationServiceProvider's
 * case, auto-discovery WOULD in fact resolve both of these correctly on
 * its own (Firm/FirmUser both live under the standard `App\Models\`
 * namespace, which Laravel's guessPolicyName() maps to `App\Policies\`
 * by default). Explicit registration here is a documentation/consistency
 * choice, not a functional necessity — see FirmPolicy's own docblock
 * for the guard-resolution caveat this still leaves open (Gate::policy()
 * is a single global mapping per model class, not scoped by auth guard).
 *
 * A dedicated provider (not an addition to AppServiceProvider) for the
 * same reason IntegrationServiceProvider is its own file: there is no
 * existing AuthServiceProvider/policy-registration convention in this
 * codebase to extend, and grouping Phase 1's platform-admin-facing
 * policy registrations together keeps this discoverable as its own unit
 * rather than growing an unrelated provider's boot() method.
 */
class PlatformAdminPolicyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Firm::class, FirmPolicy::class);
        Gate::policy(FirmUser::class, FirmUserPolicy::class);

        // MFA design proposal / Platform Administrators resource
        // addition. PlatformAdmin is simultaneously the ACTOR type for
        // every policy method in this codebase (the first argument to
        // every gate) and, here, the MODEL/SUBJECT type too — a
        // PlatformAdmin authorizing an action against another
        // PlatformAdmin record. Laravel's Gate::policy()/authorize()
        // mechanism does not require these to differ; it simply invokes
        // PlatformAdminPolicy::viewAny(PlatformAdmin $admin) /
        // ::view(PlatformAdmin $admin, PlatformAdmin $record) /
        // ::update(PlatformAdmin $admin, PlatformAdmin $record) with
        // whichever PlatformAdmin instance is currently authenticated as
        // the first argument.
        Gate::policy(PlatformAdmin::class, PlatformAdminPolicy::class);
    }
}
