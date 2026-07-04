<?php

namespace Tests\Feature\Matters;

use App\Enums\MatterStatus;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $matter = Matter::factory()->create();

        $this->assertDatabaseHas('matters', ['id' => $matter->id]);
        $this->assertSame(MatterStatus::Draft, $matter->status);
    }

    public function test_no_billing_status_or_readiness_score_columns_exist(): void
    {
        $matter = Matter::factory()->create();
        $attributes = $matter->getAttributes();

        $this->assertArrayNotHasKey('billing_status', $attributes);
        $this->assertArrayNotHasKey('readiness_score', $attributes);
    }

    public function test_stage_is_a_freeform_nullable_string(): void
    {
        $matter = Matter::factory()->create(['stage' => 'evidence_gathering']);

        $this->assertSame('evidence_gathering', $matter->fresh()->stage);
    }

    public function test_is_open_or_beyond(): void
    {
        $draft = Matter::factory()->status(MatterStatus::Draft)->create();
        $open = Matter::factory()->status(MatterStatus::Open)->create();
        $closed = Matter::factory()->status(MatterStatus::Closed)->create();

        $this->assertFalse($draft->isOpenOrBeyond());
        $this->assertTrue($open->isOpenOrBeyond());
        $this->assertTrue($closed->isOpenOrBeyond());
    }

    public function test_pinned_template_pack_version_is_nullable(): void
    {
        $matter = Matter::factory()->create(['pinned_template_pack_version_id' => null]);

        $this->assertNull($matter->pinned_template_pack_version_id);
    }
}
