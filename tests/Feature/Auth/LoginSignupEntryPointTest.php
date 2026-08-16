<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PlatformLeadStatus;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformLead;
use App\Models\User;
use App\Services\ClientPortalService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The public registration-request forms behind the login pages.
 *
 * The assertions that matter here are the negative ones. These are the only
 * unauthenticated write endpoints on the Firm and Client hosts, so the tests
 * are written around what a submission must NOT be able to do: create an
 * account, attach itself to a firm, or name a tenant record it wants access to.
 */
class LoginSignupEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private function firmHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.firm_app_url'), PHP_URL_HOST);
    }

    private function clientHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.client_portal_url'), PHP_URL_HOST);
    }

    private function adminHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.admin_url'), PHP_URL_HOST);
    }

    // ---------------------------------------------------------------- buttons

    public function test_firm_login_renders_the_register_your_firm_button(): void
    {
        $response = $this->get('https://'.$this->firmHost().'/login');

        $response->assertOk();
        $response->assertSee('Register your firm', escape: false);
        $response->assertSee(route('firm.register'), escape: false);
    }

    public function test_client_login_renders_the_request_client_access_button(): void
    {
        $response = $this->get('https://'.$this->clientHost().'/login');

        $response->assertOk();
        $response->assertSee('Request client access', escape: false);
        $response->assertSee(route('client-portal.register'), escape: false);
    }

    public function test_existing_login_ui_is_preserved(): void
    {
        $response = $this->get('https://'.$this->firmHost().'/login');

        $response->assertOk();
        $response->assertSee('Sign in', escape: false);
        $response->assertSee('wire:submit="authenticate"', escape: false);
    }

    // ------------------------------------------------------------------ forms

    public function test_firm_request_form_renders_its_fields(): void
    {
        $response = $this->get('https://'.$this->firmHost().'/register');

        $response->assertOk();
        foreach (['firm_name', 'first_name', 'last_name', 'email'] as $field) {
            $response->assertSee('name="'.$field.'"', escape: false);
        }
        $response->assertSee('Create firm account', escape: false);
        $response->assertSee('Already have an account?', escape: false);
    }

    public function test_client_request_form_renders_its_fields(): void
    {
        $response = $this->get('https://'.$this->clientHost().'/register');

        $response->assertOk();
        foreach (['first_name', 'last_name', 'email', 'firm_name', 'phone'] as $field) {
            $response->assertSee('name="'.$field.'"', escape: false);
        }
        $response->assertSee('Request client access', escape: false);
        $response->assertSee('Already have an account?', escape: false);
    }

    // --------------------------------------------------------------- creation

    public function test_firm_request_records_a_lead_and_creates_no_account(): void
    {
        $before = ['firms' => Firm::count(), 'users' => User::count()];

        $this->post('https://'.$this->firmHost().'/register', [
            'firm_name' => 'Synthetic Acceptance Firm',
            'first_name' => 'Test',
            'last_name' => 'Owner',
            'email' => 'synthetic-firm-owner@firmsbase-staging.internal',
        ])->assertRedirect(route('firm.register'));

        $lead = PlatformLead::query()->where('source', 'firm_self_registration')->sole();
        $this->assertSame('Synthetic Acceptance Firm', $lead->company_name);
        $this->assertSame('Test Owner', $lead->contact_name);
        $this->assertSame(PlatformLeadStatus::New, $lead->status);

        // The whole point: a request is not an account.
        $this->assertSame($before['firms'], Firm::count(), 'No Firm may be created by a public request.');
        $this->assertSame($before['users'], User::count(), 'No User may be created by a public request.');
        $this->assertSame(0, FirmUser::query()->count());
    }

    public function test_client_request_records_a_lead_and_grants_no_portal_access(): void
    {
        $this->post('https://'.$this->clientHost().'/register', [
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'synthetic-client@firmsbase-staging.internal',
            'firm_name' => 'Some Law Firm',
            'phone' => '+15550000000',
        ])->assertRedirect(route('client-portal.register'));

        $lead = PlatformLead::query()->where('source', 'client_access_request')->sole();
        $this->assertSame('Test Client', $lead->contact_name);
        $this->assertSame(PlatformLeadStatus::New, $lead->status);

        // Zero authorization: no portal identity, no client record, no firm link.
        $this->assertSame(0, ClientPortalUser::query()->count());
        $this->assertSame(0, Client::query()->withoutGlobalScopes()->count());
        $this->assertNull($lead->converted_organization_id);
        $this->assertNull($lead->converted_at);
    }

    public function test_a_request_cannot_claim_an_existing_tenants_records(): void
    {
        // The attack this endpoint invites: naming somebody else's ids.
        $firm = Firm::factory()->create();
        $client = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => Client::factory()->create(['firm_id' => $firm->id]),
        );

        $this->post('https://'.$this->clientHost().'/register', [
            'first_name' => 'Mallory',
            'last_name' => 'Attacker',
            'email' => 'mallory@firmsbase-staging.internal',
            'firm_name' => 'Some Law Firm',
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'matter_id' => 1,
            'uuid' => $client->uuid,
        ])->assertRedirect(route('client-portal.register'));

        $lead = PlatformLead::query()->where('source', 'client_access_request')->sole();

        // None of the injected identifiers may be persisted anywhere on the lead.
        $serialised = json_encode($lead->toArray());
        $this->assertStringNotContainsString((string) $client->uuid, (string) $serialised);
        $this->assertNull($lead->converted_organization_id);

        $this->assertSame(0, ClientPortalUser::query()->count());

        // clients is FORCE-RLS, so this must be counted inside the firm's own
        // context — an out-of-context count returns 0 because the database
        // refuses it, not because the row is gone.
        $stillOne = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn (): int => Client::query()->count(),
        );
        $this->assertSame(1, $stillOne,
            'No additional Client may be created, and the existing one must be untouched.');
    }

    public function test_requests_are_validated(): void
    {
        $this->post('https://'.$this->firmHost().'/register', [])
            ->assertSessionHasErrors(['firm_name', 'first_name', 'last_name', 'email']);

        $this->post('https://'.$this->clientHost().'/register', [])
            ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'firm_name']);

        $this->assertSame(0, PlatformLead::query()->count());
    }

    // -------------------------------------------------------------- isolation

    public function test_the_existing_client_invitation_flow_is_untouched(): void
    {
        // Self-registration must not have displaced the real path to access.
        $this->assertTrue(app('router')->has('client-portal.invitation.accept'));
        $this->assertTrue(method_exists(ClientPortalService::class, 'activate'));
        $this->assertTrue(method_exists(ClientPortalService::class, 'invite'));
    }

    public function test_admin_host_has_no_public_registration(): void
    {
        $this->get('https://'.$this->adminHost().'/register')->assertNotFound();
        $this->post('https://'.$this->adminHost().'/register', [])->assertNotFound();

        $this->assertFalse(app('router')->has('admin.register'));
    }

    public function test_routes_do_not_exist_on_any_other_host(): void
    {
        // Route::domain()-scoped. (TrustHosts' own 400 for a spoofed Host header
        // does not engage in the testing environment; that is verified against
        // live staging instead, so this asserts only what it proves.)
        $this->get('https://evil.example.com/register')->assertNotFound();
        $this->get('https://'.$this->clientHost().'/register')->assertOk();
        $this->get('https://'.$this->firmHost().'/register')->assertOk();
    }

    public function test_csrf_protection_is_attached_to_the_request_endpoints(): void
    {
        // Laravel's test harness disables CSRF for the whole case, so asserting
        // a 419 here would only prove the harness works. The honest check is
        // that the middleware is actually gathered onto these routes, and that
        // the forms post a token.
        foreach (['firm.register.store', 'client-portal.register.store'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route [{$name}] must exist.");

            // This Laravel version gathers PreventRequestForgery — the CSRF
            // middleware has been renamed twice (VerifyCsrfToken ->
            // ValidateCsrfToken -> PreventRequestForgery), and only the current
            // name is what actually lands on the route.
            $middleware = app('router')->gatherRouteMiddleware($route);
            $this->assertContains(PreventRequestForgery::class, $middleware,
                "Route [{$name}] must be CSRF protected.");

            // Session must start before CSRF can mean anything.
            $this->assertContains(StartSession::class, $middleware);
        }

        foreach ([$this->firmHost(), $this->clientHost()] as $host) {
            $this->get('https://'.$host.'/register')
                ->assertSee('name="_token"', escape: false);
        }
    }

    public function test_request_pages_set_no_parent_domain_cookie(): void
    {
        foreach ([$this->firmHost(), $this->clientHost()] as $host) {
            $response = $this->get('https://'.$host.'/register');

            foreach ($response->headers->getCookies() as $cookie) {
                $this->assertNotSame('.firmsvault.com', $cookie->getDomain());
            }
        }
    }
}
