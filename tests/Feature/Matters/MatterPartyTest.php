<?php

namespace Tests\Feature\Matters;

use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterPartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $matterParty = MatterParty::factory()->create();

        $this->assertDatabaseHas('matter_parties', ['id' => $matterParty->id]);
    }

    public function test_no_firm_id_column_exists(): void
    {
        $matterParty = MatterParty::factory()->create();

        $this->assertArrayNotHasKey('firm_id', $matterParty->getAttributes());
    }

    public function test_unique_matter_party_pair(): void
    {
        $matter = Matter::factory()->create();
        $party = Party::factory()->forFirm($matter->firm)->create();

        MatterParty::factory()->forMatter($matter)->forParty($party)->create();

        $this->expectException(QueryException::class);

        MatterParty::factory()->forMatter($matter)->forParty($party)->create();
    }
}
