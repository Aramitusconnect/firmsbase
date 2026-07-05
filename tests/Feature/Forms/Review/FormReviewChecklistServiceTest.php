<?php

namespace Tests\Feature\Forms\Review;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Services\FormReviewChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormReviewChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormReviewChecklistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormReviewChecklistService();
    }

    public function test_seed_defaults_creates_the_four_fixed_items_exactly_once(): void
    {
        $draft = FormDraft::factory()->create();

        $this->service->seedDefaults($draft);
        $this->service->seedDefaults($draft);

        $this->assertSame(4, $draft->checklistItems()->count());
        $this->assertFalse($this->service->isComplete($draft));
    }

    public function test_is_complete_only_when_every_seeded_item_is_checked(): void
    {
        $draft = FormDraft::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $draft->firm_id]);
        $this->service->seedDefaults($draft);

        foreach ($draft->checklistItems as $item) {
            $this->service->check($item, $actor);
        }

        $this->assertTrue($this->service->isComplete($draft->fresh()));
    }

    public function test_uncheck_reverts_is_checked_and_actor(): void
    {
        $draft = FormDraft::factory()->create();
        $actor = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $draft->firm_id]);
        $this->service->seedDefaults($draft);
        $item = $draft->checklistItems()->first();

        $checked = $this->service->check($item, $actor);
        $this->assertTrue($checked->is_checked);

        $unchecked = $this->service->uncheck($checked);
        $this->assertFalse($unchecked->is_checked);
        $this->assertNull($unchecked->checked_by_firm_user_id);
    }

    public function test_is_complete_is_false_with_zero_seeded_items(): void
    {
        $draft = FormDraft::factory()->create();

        $this->assertFalse($this->service->isComplete($draft));
    }
}
