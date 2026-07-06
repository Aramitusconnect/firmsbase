<?php

namespace Tests\Feature\Governance\AccessReview;

use App\Enums\AccessReviewItemDecision;
use App\Enums\AccessReviewScope;
use App\Enums\AccessReviewStatus;
use App\Models\ApiKey;
use App\Services\AccessReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\Concerns\SetsUpGovernanceFirm;
use Tests\TestCase;

class AccessReviewServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpGovernanceFirm;

    private AccessReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccessReviewService::class);
    }

    public function test_cannot_complete_while_items_are_pending(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->makePlatformAdmin();

        $review = $this->service->initiate(AccessReviewScope::PlatformAdmins, $admin);

        $this->assertGreaterThan(0, $review->items()->count());

        $this->expectException(\RuntimeException::class);
        $this->service->complete($review);
    }

    public function test_can_complete_once_every_item_is_reviewed(): void
    {
        $admin = $this->makePlatformAdmin();

        $review = $this->service->initiate(AccessReviewScope::PlatformAdmins, $admin);

        foreach ($review->items as $item) {
            $this->service->recordDecision($item, AccessReviewItemDecision::Retain, $admin);
        }

        $completed = $this->service->complete($review->fresh());

        $this->assertSame(AccessReviewStatus::Completed, $completed->status);
    }

    public function test_revoke_decision_does_not_automatically_revoke_the_subject(): void
    {
        $firm = $this->makeGovernanceFirm();
        $admin = $this->makePlatformAdmin();
        $apiKey = ApiKey::factory()->create(['firm_id' => $firm->id]);

        $review = $this->service->initiate(AccessReviewScope::ApiKeys, $admin, $firm);
        $item = $review->items()->where('subject_id', $apiKey->id)->first();

        $this->service->recordDecision($item, AccessReviewItemDecision::Revoke, $admin);

        // Record-only: the api_keys row itself is untouched.
        $this->assertDatabaseHas('api_keys', ['id' => $apiKey->id, 'status' => $apiKey->fresh()->status->value]);
        $this->assertSame($apiKey->status, $apiKey->fresh()->status);
    }

    public function test_access_review_item_freezes_after_a_decision_is_recorded(): void
    {
        $admin = $this->makePlatformAdmin();
        $review = $this->service->initiate(AccessReviewScope::PlatformAdmins, $admin);
        $item = $review->items->first();

        $this->service->recordDecision($item, AccessReviewItemDecision::Retain, $admin);

        $this->expectException(\LogicException::class);
        $item->fresh()->update(['notes' => 'trying to edit after decision']);
    }
}
