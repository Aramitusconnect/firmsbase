<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Services\ClientPortalService;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ClientPortalActivationFlowTest — Checkpoint 4 ("Plaid financial
 * evidence add-on"), Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2.5.1). Proves
 * `ClientPortalService::activate()` — the ONE behavioral addition this
 * checkpoint made to the pre-existing invitation lifecycle
 * (invite()/acceptInvitation()/disable(), already covered by
 * tests/Feature/Clients/ClientPortalServiceTest.php and not duplicated
 * here) — using the real `Client::portal_invitation_token`/
 * `portal_status` columns end to end: an invited client can set a
 * password and receive a genuine, working `ClientPortalUser` login
 * credential, entirely through production code, no synthetic
 * shortcuts.
 */
class ClientPortalActivationFlowTest extends TestCase
{
    use RefreshDatabase;

    private ClientPortalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClientPortalService(new ConsentService);
    }

    public function test_activate_creates_a_working_client_portal_user_credential(): void
    {
        $client = Client::factory()->create();
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);

        $portalUser = $this->service->activate($invited, $invited->portal_invitation_token, 'Sup3rSecret!Pass');

        $this->assertInstanceOf(ClientPortalUser::class, $portalUser);
        $this->assertSame($client->id, $portalUser->client_id);
        $this->assertSame($client->email, $portalUser->email);
        $this->assertTrue($portalUser->is_active);
        $this->assertTrue(Hash::check('Sup3rSecret!Pass', $portalUser->password));
    }

    public function test_activate_also_completes_the_underlying_invitation_lifecycle(): void
    {
        $client = Client::factory()->create();
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);

        $this->service->activate($invited, $invited->portal_invitation_token, 'Sup3rSecret!Pass');

        $fresh = $this->runWithFirmContext($client->firm, fn () => Client::query()->find($client->id));
        $this->assertSame(ClientPortalStatus::Active, $fresh->portal_status);
        $this->assertNull($fresh->portal_invitation_token);
        $this->assertNotNull($fresh->portal_invitation_accepted_at);
    }

    public function test_activate_throws_on_an_invalid_token_and_creates_no_credential(): void
    {
        $client = Client::factory()->create();
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);

        try {
            $this->service->activate($invited, 'wrong-token', 'Sup3rSecret!Pass');
            $this->fail('Expected a RuntimeException for an invalid activation token.');
        } catch (\RuntimeException) {
            // expected
        }

        $exists = $this->runWithFirmContext($client->firm, fn () => ClientPortalUser::query()->where('client_id', $client->id)->exists());
        $this->assertFalse($exists, 'No ClientPortalUser row must be created when the activation token is invalid.');
    }

    public function test_activated_client_can_authenticate_via_the_client_guard_with_the_chosen_password(): void
    {
        $client = Client::factory()->create();
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);
        $this->service->activate($invited, $invited->portal_invitation_token, 'Sup3rSecret!Pass');

        $attempted = Auth::guard('client')->attempt(['email' => $client->email, 'password' => 'Sup3rSecret!Pass']);

        $this->assertTrue($attempted, 'The client guard must authenticate with the password chosen during activation.');
        $this->assertTrue(Auth::guard('client')->check());
    }

    public function test_activated_client_cannot_authenticate_with_the_wrong_password(): void
    {
        $client = Client::factory()->create();
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);
        $this->service->activate($invited, $invited->portal_invitation_token, 'Sup3rSecret!Pass');

        $attempted = Auth::guard('client')->attempt(['email' => $client->email, 'password' => 'totally-wrong']);

        $this->assertFalse($attempted);
    }

    public function test_re_activating_after_a_reissued_invitation_updates_the_same_credential_row_not_a_duplicate(): void
    {
        // client_id is a unique FK — updateOrCreate() makes activate()
        // safely re-callable after disable() -> re-invite(), never
        // producing a second row for the same Client.
        $client = Client::factory()->create();
        app(ConsentService::class)->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invitedFirstTime = $this->service->invite($client);
        $firstPortalUser = $this->service->activate($invitedFirstTime, $invitedFirstTime->portal_invitation_token, 'FirstPassw0rd!');

        $this->service->disable($client);
        $reInvited = $this->service->invite($client->fresh());
        $secondPortalUser = $this->service->activate($reInvited, $reInvited->portal_invitation_token, 'SecondPassw0rd!');

        $this->assertSame($firstPortalUser->id, $secondPortalUser->id, 'Re-activation must update the same ClientPortalUser row, not create a second one.');

        $count = $this->runWithFirmContext($client->firm, fn () => ClientPortalUser::query()->where('client_id', $client->id)->count());
        $this->assertSame(1, $count);

        $this->assertTrue(Hash::check('SecondPassw0rd!', $secondPortalUser->fresh()->password));
    }
}
