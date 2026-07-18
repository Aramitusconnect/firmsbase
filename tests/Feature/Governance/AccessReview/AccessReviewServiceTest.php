<?php

namespace Tests\Feature\Governance\AccessReview;

use App\Enums\AccessReviewItemDecision;
use App\Enums\AccessReviewScope;
use App\Enums\AccessReviewStatus;
use App\Enums\SupportAccessType;
use App\Models\ApiKey;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
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

    /**
     * Required proof item #5 (Wave 8 governance-domain empirical
     * verification): AccessReviewScope::SupportAgents' own private
     * supportAccessRequesterIdsAcrossAllFirms() helper genuinely
     * aggregates across every firm rather than only seeing one, now
     * that support_access_requests carries permanent FORCE ROW LEVEL
     * SECURITY (a strict, non-nullable firm_id policy with no
     * NULL-context bypass — an unwrapped cross-firm query would
     * silently return nothing). Two different firms, each with their
     * own distinct requested_by platform admin, must BOTH surface as
     * AccessReviewItems after a single initiate() call.
     */
    public function test_support_agents_scope_aggregates_requesters_across_multiple_firms(): void
    {
        $firmA = $this->makeGovernanceFirm();
        $firmB = $this->makeGovernanceFirm();
        $requesterA = PlatformAdmin::factory()->create();
        $requesterB = PlatformAdmin::factory()->create();
        $initiator = $this->makePlatformAdmin();

        $this->runWithFirmContext($firmA, fn () => SupportAccessRequest::factory()->create([
            'firm_id' => $firmA->id,
            'requested_by' => $requesterA->id,
            'access_type' => SupportAccessType::Standard->value,
        ]));
        $this->runWithFirmContext($firmB, fn () => SupportAccessRequest::factory()->create([
            'firm_id' => $firmB->id,
            'requested_by' => $requesterB->id,
            'access_type' => SupportAccessType::Standard->value,
        ]));

        $review = $this->service->initiate(AccessReviewScope::SupportAgents, $initiator);

        $reviewedAdminIds = $review->items()->get()->pluck('subject_id')->all();

        $this->assertContains($requesterA->id, $reviewedAdminIds, 'Firm A\'s support access requester must be included — proving the per-firm loop genuinely reaches firm A.');
        $this->assertContains($requesterB->id, $reviewedAdminIds, 'Firm B\'s support access requester must be included too — proving the per-firm loop aggregates across BOTH firms rather than only seeing whichever firm happened to be checked first.');
    }
}
