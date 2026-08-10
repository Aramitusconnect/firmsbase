<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Proves bootstrap/app.php's guard-aware `redirectGuestsTo()` (Checkpoint 4,
 * "Plaid financial evidence add-on", test-gate fix). Laravel's own
 * ApplicationBuilder::withMiddleware() unconditionally registers a default
 * `redirectGuestsTo(fn () => route('login'))` before the app's own
 * withMiddleware() callback runs — and this app has no plain `login` named
 * route anywhere (every guard authenticates through a Filament-scoped route).
 * Left unfixed, ANY unauthenticated request to a raw, guard-protected,
 * non-Filament HTTP route throws RouteNotFoundException ("Route [login] not
 * defined"), a 500, instead of a clean redirect to that guard's own login
 * page — confirmed empirically against both the Checkpoint 4 Client Portal
 * Plaid exchange route (`auth:client`) and the pre-existing Checkpoint 5
 * integration OAuth routes (`auth`, default `web` guard).
 */
class GuestRedirectTest extends TestCase
{
    public function test_unauthenticated_post_to_client_portal_plaid_exchange_redirects_to_the_client_portal_login_page(): void
    {
        $response = $this->post($this->clientPortalUrl('/plaid/exchange'));

        $response->assertRedirect(route('filament.client-portal.auth.login'));
    }

    public function test_unauthenticated_get_to_integration_oauth_initiate_redirects_to_the_firm_login_page(): void
    {
        // {firmIntegration} is deliberately a plain route string, not an
        // implicit model binding (see OAuthConnectionController's own
        // docblock) — the guest-redirect middleware fires before the
        // controller is ever reached, so no real FirmIntegration row is
        // needed here.
        $response = $this->get($this->firmAppUrl('/integrations/oauth/1/initiate'));

        $response->assertRedirect(route('filament.firm.auth.login'));
    }

    public function test_unauthenticated_get_to_admin_panel_redirects_to_the_admin_login_page(): void
    {
        $response = $this->get($this->adminUrl());

        $response->assertRedirect(route('filament.admin.auth.login'));
    }
}
