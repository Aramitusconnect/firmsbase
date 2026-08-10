<?php

namespace Tests\Feature\Security\Login;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ClientPortalLoginPanelAccessTest — Mission 1 (Domain & Security
 * Boundary Architecture), test matrix O/P/T. Mirrors
 * FirmUserLoginPanelAccessTest's own shape exactly, for the `client`
 * guard + Client Portal panel: Client::canAccessPanel() is the sole
 * gate, and it is satisfied ONLY by ClientPortalStatus::Active — the
 * state ClientPortalService::acceptInvitation() alone can reach.
 */
class ClientPortalLoginPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_portal_client_can_reach_the_client_portal_dashboard(): void
    {
        $client = Client::factory()->activeOnPortal()->create();

        $response = $this->actingAs($client, 'client')->get($this->clientPortalUrl('/'));

        $response->assertOk();
    }

    public function test_a_guest_cannot_reach_the_client_portal_dashboard(): void
    {
        $response = $this->get($this->clientPortalUrl('/'));

        $response->assertRedirect($this->clientPortalUrl('/login'));
    }

    public function test_a_not_invited_client_cannot_reach_the_client_portal_dashboard(): void
    {
        $client = Client::factory()->create(['portal_status' => ClientPortalStatus::NotInvited]);

        $response = $this->actingAs($client, 'client')->get($this->clientPortalUrl('/'));

        $response->assertForbidden();
    }

    public function test_an_invited_but_not_yet_accepted_client_cannot_reach_the_client_portal_dashboard(): void
    {
        $client = Client::factory()->create(['portal_status' => ClientPortalStatus::Invited]);

        $response = $this->actingAs($client, 'client')->get($this->clientPortalUrl('/'));

        $response->assertForbidden();
    }

    public function test_a_disabled_portal_client_cannot_reach_the_client_portal_dashboard(): void
    {
        $client = Client::factory()->activeOnPortal()->create(['portal_status' => ClientPortalStatus::Disabled]);

        $response = $this->actingAs($client, 'client')->get($this->clientPortalUrl('/'));

        $response->assertForbidden();
    }
}
