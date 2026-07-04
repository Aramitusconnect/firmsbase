<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $contact = Contact::factory()->create();

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_it_can_exist_without_a_client(): void
    {
        $contact = Contact::factory()->create(['client_id' => null]);

        $this->assertNull($contact->client_id);
    }

    public function test_it_can_belong_to_a_client(): void
    {
        $client = Client::factory()->create();
        $contact = Contact::factory()->create(['firm_id' => $client->firm_id, 'client_id' => $client->id]);

        $this->assertSame($client->id, $contact->client->id);
    }
}
