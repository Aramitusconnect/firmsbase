<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Notifications\ClientPortalInvitationNotification;
use App\Services\ClientPortalService;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ClientPortalInvitationFlowTest — Mission 3A (MyAttorney Launch-Flow
 * Closure). Proves invite() actually sends an email (via the real
 * DB::afterCommit()-deferred send path — DatabaseMigrations, not
 * RefreshDatabase, for the same reason
 * MarketplaceIntakeNotificationWiringTest documents), that the signed
 * invitation URL/token-based self-lookup resolves correctly and stays
 * isolated across firms, and that a stale invitation cannot reactivate
 * portal access the Firm has since revoked.
 */
class ClientPortalInvitationFlowTest extends TestCase
{
    use DatabaseMigrations;

    private ClientPortalService $service;

    private ConsentService $consentService;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->consentService = new ConsentService;
        $this->service = new ClientPortalService($this->consentService);
    }

    private function consentedClient(string $email = 'client@example.com'): Client
    {
        $client = Client::factory()->create(['email' => $email]);
        $this->consentService->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');

        return $client;
    }

    public function test_invite_notifies_the_client_at_their_own_email(): void
    {
        $client = $this->consentedClient('client@example.com');

        $this->service->invite($client);

        Notification::assertSentOnDemand(
            ClientPortalInvitationNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable instanceof AnonymousNotifiable
                && $notifiable->routes['mail'] === 'client@example.com',
        );
    }

    public function test_invite_still_succeeds_even_when_the_client_has_no_email(): void
    {
        $client = $this->consentedClient();
        $this->runWithFirmContext($client->firm, fn () => $client->update(['email' => null]));

        $invited = $this->service->invite($client->fresh());

        $this->assertSame(ClientPortalStatus::Invited, $invited->portal_status);
        Notification::assertNothingSent();
    }

    public function test_invitation_url_is_a_valid_signed_url_containing_the_token(): void
    {
        $client = $this->consentedClient();
        $invited = $this->service->invite($client);

        $url = $this->service->invitationUrl($invited);

        $this->assertStringContainsString('accept-invitation', $url);
        $this->assertStringContainsString($invited->portal_invitation_token, $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    public function test_resolve_by_invitation_token_finds_the_correct_client(): void
    {
        $client = $this->consentedClient();
        $invited = $this->service->invite($client);

        $resolved = $this->service->resolveByInvitationToken($invited->portal_invitation_token);

        $this->assertNotNull($resolved);
        $this->assertSame($invited->id, $resolved->id);
    }

    public function test_resolve_by_invitation_token_returns_null_for_an_unknown_token(): void
    {
        $resolved = $this->service->resolveByInvitationToken((string) Str::uuid7());

        $this->assertNull($resolved);
    }

    public function test_resolve_by_invitation_token_returns_null_once_the_token_has_been_consumed(): void
    {
        $client = $this->consentedClient();
        $invited = $this->service->invite($client);
        $token = $invited->portal_invitation_token;

        $this->service->acceptInvitation($invited, $token);

        $resolved = $this->service->resolveByInvitationToken($token);

        $this->assertNull($resolved, 'A consumed token must never resolve again — single-use.');
    }

    public function test_tokens_stay_isolated_across_firms(): void
    {
        $clientA = $this->consentedClient('a@example.com');
        $clientB = $this->consentedClient('b@example.com');
        $invitedA = $this->service->invite($clientA);
        $invitedB = $this->service->invite($clientB);

        $resolvedA = $this->service->resolveByInvitationToken($invitedA->portal_invitation_token);
        $resolvedB = $this->service->resolveByInvitationToken($invitedB->portal_invitation_token);

        $this->assertSame($clientA->id, $resolvedA->id);
        $this->assertSame($clientB->id, $resolvedB->id);
        $this->assertNotSame($resolvedA->id, $resolvedB->id);
    }

    public function test_reinviting_rotates_the_token_and_invalidates_the_previous_link(): void
    {
        $client = $this->consentedClient();
        $first = $this->service->invite($client);
        $firstToken = $first->portal_invitation_token;

        $second = $this->service->invite($first->fresh());

        $this->assertNotSame($firstToken, $second->portal_invitation_token);
        $this->assertNull($this->service->resolveByInvitationToken($firstToken));
        $this->assertNotNull($this->service->resolveByInvitationToken($second->portal_invitation_token));
    }

    public function test_accept_invitation_rejects_a_stale_token_after_the_firm_disabled_access(): void
    {
        $client = $this->consentedClient();
        $invited = $this->service->invite($client);
        $token = $invited->portal_invitation_token;

        // The Firm revokes access before the client ever clicks the
        // link — disable() does not itself clear the token, so a
        // naive token-only check would wrongly let this stale link
        // reactivate access the Firm explicitly revoked.
        $this->service->disable($invited->fresh());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This invitation is no longer active.');

        $this->service->acceptInvitation($invited->fresh(), $token);
    }

    public function test_activate_creates_a_client_portal_user_and_never_a_second_client(): void
    {
        $client = $this->consentedClient();
        $invited = $this->service->invite($client);

        $portalUser = $this->service->activate($invited, $invited->portal_invitation_token, 'a-real-password-123');

        $this->assertSame($invited->id, $portalUser->client_id);
        $clientCount = $this->runWithFirmContext($client->firm, fn () => Client::query()->count());
        $this->assertSame(1, $clientCount, 'activate() must never create a second Client row.');
    }
}
