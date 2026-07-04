<?php

namespace Tests\Feature\Clients;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $client = Client::factory()->create();

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertSame(ClientPortalStatus::NotInvited, $client->portal_status);
    }

    public function test_uuid_is_generated(): void
    {
        $client = Client::factory()->create();

        $this->assertNotEmpty($client->uuid);
    }
}
