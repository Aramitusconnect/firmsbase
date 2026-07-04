<?php

namespace Tests\Feature\Clients;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Services\ClientPortalService;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortalServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientPortalService $service;
    private ConsentService $consentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consentService = new ConsentService();
        $this->service = new ClientPortalService($this->consentService);
    }

    public function test_invite_throws_without_granted_portal_consent(): void
    {
        $client = Client::factory()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->invite($client);
    }

    public function test_invite_succeeds_with_granted_portal_consent(): void
    {
        $client = Client::factory()->create();
        $this->consentService->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');

        $invited = $this->service->invite($client);

        $this->assertSame(ClientPortalStatus::Invited, $invited->portal_status);
        $this->assertNotNull($invited->portal_invitation_token);
        $this->assertNotNull($invited->portal_invitation_sent_at);
    }

    public function test_accept_invitation_activates_portal_and_clears_token(): void
    {
        $client = Client::factory()->create();
        $this->consentService->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);

        $activated = $this->service->acceptInvitation($invited, $invited->portal_invitation_token);

        $this->assertSame(ClientPortalStatus::Active, $activated->portal_status);
        $this->assertNull($activated->portal_invitation_token);
        $this->assertNotNull($activated->portal_invitation_accepted_at);
    }

    public function test_accept_invitation_throws_on_wrong_token(): void
    {
        $client = Client::factory()->create();
        $this->consentService->capture($client->firm, $client->id, ConsentChannel::Portal, 'v1');
        $invited = $this->service->invite($client);

        $this->expectException(\RuntimeException::class);

        $this->service->acceptInvitation($invited, 'wrong-token');
    }

    public function test_disable_sets_status_disabled(): void
    {
        $client = Client::factory()->create();

        $disabled = $this->service->disable($client);

        $this->assertSame(ClientPortalStatus::Disabled, $disabled->portal_status);
    }
}
