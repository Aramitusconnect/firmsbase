<?php

namespace Tests\Feature\Activation;

use App\Enums\ActivationChecklistStatus;
use App\Models\ActivationChecklist;
use App\Models\ActivationChecklistItem;
use App\Models\Firm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $checklist = ActivationChecklist::factory()->create();

        $this->assertDatabaseHas('activation_checklists', ['id' => $checklist->id]);
        $this->assertSame(ActivationChecklistStatus::InProgress, $checklist->status);
    }

    public function test_only_one_checklist_per_firm(): void
    {
        $firm = Firm::factory()->create();
        ActivationChecklist::factory()->forFirm($firm)->create();

        $this->expectException(QueryException::class);

        ActivationChecklist::factory()->forFirm($firm)->create();
    }

    public function test_all_required_items_satisfied_true_when_no_items_exist(): void
    {
        $checklist = ActivationChecklist::factory()->create();

        $this->assertTrue($checklist->allRequiredItemsSatisfied());
    }

    public function test_all_required_items_satisfied_false_when_a_required_item_is_incomplete(): void
    {
        $checklist = ActivationChecklist::factory()->create();
        ActivationChecklistItem::factory()->forChecklist($checklist)->create();

        $this->assertFalse($checklist->allRequiredItemsSatisfied());
    }

    public function test_all_required_items_satisfied_true_when_incomplete_item_is_optional(): void
    {
        $checklist = ActivationChecklist::factory()->create();
        ActivationChecklistItem::factory()->forChecklist($checklist)->optional()->create();

        $this->assertTrue($checklist->allRequiredItemsSatisfied());
    }

    public function test_all_required_items_satisfied_true_when_incomplete_item_is_waived(): void
    {
        $checklist = ActivationChecklist::factory()->create();
        ActivationChecklistItem::factory()->forChecklist($checklist)->waived()->create();

        $this->assertTrue($checklist->allRequiredItemsSatisfied());
    }

    public function test_all_required_items_satisfied_true_when_required_item_is_complete(): void
    {
        $checklist = ActivationChecklist::factory()->create();
        ActivationChecklistItem::factory()->forChecklist($checklist)->complete()->create();

        $this->assertTrue($checklist->allRequiredItemsSatisfied());
    }

    public function test_unique_item_key_per_checklist(): void
    {
        $checklist = ActivationChecklist::factory()->create();
        ActivationChecklistItem::factory()->forChecklist($checklist)->create(['item_key' => 'billing_confirmed']);

        $this->expectException(QueryException::class);

        ActivationChecklistItem::factory()->forChecklist($checklist)->create(['item_key' => 'billing_confirmed']);
    }
}
