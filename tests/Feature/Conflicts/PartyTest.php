<?php

namespace Tests\Feature\Conflicts;

use App\Enums\PartyEntityType;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $party = Party::factory()->create();

        $this->assertDatabaseHas('parties', ['id' => $party->id]);
        $this->assertSame(PartyEntityType::Individual, $party->entity_type);
    }

    public function test_company_state_sets_entity_type(): void
    {
        $party = Party::factory()->company()->create();

        $this->assertSame(PartyEntityType::Company, $party->fresh()->entity_type);
    }
}
