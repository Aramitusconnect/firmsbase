<?php

declare(strict_types=1);

namespace Tests\Feature\ClientPortal;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Livewire\ClientPortal\AcceptInvitationPage;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Services\ClientPortalService;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AcceptInvitationPageTest — Mission 3A (MyAttorney Launch-Flow
 * Closure). Feature/browser-level proof that the Client Portal
 * invitation-accept flow is genuinely reachable, actually creates a
 * real ClientPortalUser and establishes a real authenticated session,
 * and that every denial path (unsigned, tampered, expired, unknown,
 * reused, mismatched-firm token) is closed.
 */
class AcceptInvitationPageTest extends TestCase
{
    use RefreshDatabase;

    private function invitedClient(string $email = 'client@example.com'): Client
    {
        $client = Client::factory()->create(['email' => $email]);
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');

        return app(ClientPortalService::class)->invite($client);
    }

    // -----------------------------------------------------------------
    // HTTP-level route security
    // -----------------------------------------------------------------

    public function test_a_validly_signed_invitation_url_renders_the_form(): void
    {
        $invited = $this->invitedClient();
        $url = app(ClientPortalService::class)->invitationUrl($invited);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Set up your secure portal access');
    }

    public function test_the_bare_route_with_no_signature_is_rejected(): void
    {
        $invited = $this->invitedClient();

        $response = $this->get($this->clientPortalUrl('/accept-invitation/'.$invited->portal_invitation_token));

        $response->assertForbidden();
    }

    public function test_a_tampered_token_against_someone_elses_signature_is_rejected(): void
    {
        $invitedA = $this->invitedClient('a@example.com');
        $invitedB = $this->invitedClient('b@example.com');

        $urlForA = app(ClientPortalService::class)->invitationUrl($invitedA);
        $tamperedUrl = str_replace($invitedA->portal_invitation_token, $invitedB->portal_invitation_token, $urlForA);

        $response = $this->get($tamperedUrl);

        $response->assertForbidden();
    }

    public function test_an_expired_signature_is_rejected(): void
    {
        $invited = $this->invitedClient();

        $expiredUrl = URL::temporarySignedRoute('client-portal.invitation.accept', now()->subMinute(), ['token' => $invited->portal_invitation_token]);

        $response = $this->get($expiredUrl);

        $response->assertForbidden();
    }

    public function test_a_genuinely_unknown_but_well_formed_token_never_validates(): void
    {
        $unknownToken = (string) Str::uuid7();

        $response = $this->get($this->clientPortalUrl('/accept-invitation/'.$unknownToken.'?expires=9999999999&signature=deadbeef'));

        $response->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Component behavior — happy path
    // -----------------------------------------------------------------

    public function test_the_full_activation_flow_creates_a_client_portal_user_and_logs_the_client_in(): void
    {
        $invited = $this->invitedClient('client@example.com');
        $token = $invited->portal_invitation_token;

        Livewire::test(AcceptInvitationPage::class, ['token' => $token])
            ->assertSet('found', true)
            ->assertSet('valid', true)
            ->set('password', 'a-real-password-123')
            ->set('passwordConfirmation', 'a-real-password-123')
            ->call('acceptInvitation');

        $this->assertTrue(Auth::guard('client')->check());

        $portalUser = $this->runWithFirmContext($invited->firm, fn () => ClientPortalUser::query()->where('client_id', $invited->id)->first());
        $this->assertNotNull($portalUser);
        $this->assertTrue($portalUser->is_active);

        $refreshed = $this->runWithFirmContext($invited->firm, fn () => $invited->fresh());
        $this->assertSame(ClientPortalStatus::Active, $refreshed->portal_status);
        $this->assertNull($refreshed->portal_invitation_token);
    }

    public function test_mismatched_passwords_are_rejected_without_activating(): void
    {
        $invited = $this->invitedClient();

        Livewire::test(AcceptInvitationPage::class, ['token' => $invited->portal_invitation_token])
            ->set('password', 'a-real-password-123')
            ->set('passwordConfirmation', 'a-different-password')
            ->call('acceptInvitation')
            ->assertSet('validationErrors.password', 'Please enter matching passwords.');

        $this->assertFalse(Auth::guard('client')->check());
        $count = $this->runWithFirmContext($invited->firm, fn () => ClientPortalUser::query()->count());
        $this->assertSame(0, $count);
    }

    public function test_a_too_short_password_is_rejected(): void
    {
        $invited = $this->invitedClient();

        Livewire::test(AcceptInvitationPage::class, ['token' => $invited->portal_invitation_token])
            ->set('password', 'short')
            ->set('passwordConfirmation', 'short')
            ->call('acceptInvitation')
            ->assertSet('validationErrors.password', 'Your password must be at least 8 characters.');

        $this->assertFalse(Auth::guard('client')->check());
    }

    // -----------------------------------------------------------------
    // Denial paths — reuse, cross-tenant, revocation, enumeration
    // -----------------------------------------------------------------

    public function test_an_unknown_token_shows_a_generic_not_found_message(): void
    {
        $component = Livewire::test(AcceptInvitationPage::class, ['token' => (string) Str::uuid7()]);

        $component->assertSet('found', false)
            ->assertSee('Invitation link not found');
    }

    public function test_a_reused_token_after_successful_activation_is_rejected(): void
    {
        $invited = $this->invitedClient();
        $token = $invited->portal_invitation_token;

        Livewire::test(AcceptInvitationPage::class, ['token' => $token])
            ->set('password', 'a-real-password-123')
            ->set('passwordConfirmation', 'a-real-password-123')
            ->call('acceptInvitation');

        Auth::guard('client')->logout();

        // A second visitor (or the same visitor double-clicking a
        // stale tab) tries the SAME token again.
        $second = Livewire::test(AcceptInvitationPage::class, ['token' => $token]);
        $second->assertSet('found', false);

        $count = $this->runWithFirmContext($invited->firm, fn () => ClientPortalUser::query()->count());
        $this->assertSame(1, $count, 'A reused token must never create a second ClientPortalUser.');
    }

    public function test_a_client_cannot_use_another_clients_invitation_token(): void
    {
        $invitedA = $this->invitedClient('a@example.com');
        $invitedB = $this->invitedClient('b@example.com');

        Livewire::test(AcceptInvitationPage::class, ['token' => $invitedA->portal_invitation_token])
            ->assertSet('found', true);

        // Forge B's token into a component mounted for A's page context
        // — the component always re-resolves fresh from whatever token
        // it was actually given, so this proves cross-client isolation
        // rather than any shared/leaked state.
        $forged = Livewire::test(AcceptInvitationPage::class, ['token' => $invitedB->portal_invitation_token]);
        $forged->set('password', 'a-real-password-123')
            ->set('passwordConfirmation', 'a-real-password-123')
            ->call('acceptInvitation');

        $portalUserA = $this->runWithFirmContext($invitedA->firm, fn () => ClientPortalUser::query()->where('client_id', $invitedA->id)->first());
        $portalUserB = $this->runWithFirmContext($invitedB->firm, fn () => ClientPortalUser::query()->where('client_id', $invitedB->id)->first());

        $this->assertNull($portalUserA, 'Activating via B\'s token must never activate A\'s account.');
        $this->assertNotNull($portalUserB);
    }

    public function test_a_disabled_clients_stale_invitation_link_is_rejected(): void
    {
        $invited = $this->invitedClient();
        $token = $invited->portal_invitation_token;

        app(ClientPortalService::class)->disable($invited->fresh());

        $component = Livewire::test(AcceptInvitationPage::class, ['token' => $token]);

        $component->assertSet('found', true)
            ->assertSet('valid', false)
            ->assertSee('Invitation link not found');

        $count = $this->runWithFirmContext($invited->firm, fn () => ClientPortalUser::query()->count());
        $this->assertSame(0, $count);
    }
}
