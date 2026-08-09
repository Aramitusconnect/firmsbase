<?php

namespace App\Services\Stripe;

/**
 * PaymentGatewaySimulationPolicyService — Payment-Channel Safety
 * Hardening pass, item 1. The ONE place "is it safe to use
 * FakeStripeGateway right now" is decided — both
 * AppServiceProvider::register()'s StripeGateway binding and any
 * UI-layer "is online payment available" check (PublicPaymentPage)
 * must call this rather than re-deriving the rule.
 *
 * Rule, deliberately asymmetric between local and testing (mirrors the
 * `app()->environment(['local', 'testing'])` staging/production
 * write-guard pattern already established by ProvisionFirmCommand/
 * BootstrapStagingSandboxPlanCommand, but split apart here because the
 * two environments are NOT treated the same for this specific
 * decision):
 *
 *   - testing: ALWAYS simulated. This is what every existing
 *     FakeStripeGateway-based test in this codebase already assumes;
 *     changing it would break the entire pre-existing test suite for
 *     no safety benefit (a test database is never staging/production
 *     money).
 *   - local: simulated ONLY when services.stripe.gateway_simulation_enabled
 *     is explicitly true (PAYMENT_GATEWAY_SIMULATION_ENABLED=true) —
 *     a developer must opt in; local does NOT automatically simulate.
 *   - every other environment (staging, production, anything else):
 *     NEVER simulated, regardless of the config value. The env var
 *     literally cannot take effect outside local — there is no way to
 *     misconfigure a staging/production box into silently accepting a
 *     fake payment via this flag.
 */
class PaymentGatewaySimulationPolicyService
{
    public function isSimulationEnabled(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return app()->environment('local') && (bool) config('services.stripe.gateway_simulation_enabled', false);
    }
}
