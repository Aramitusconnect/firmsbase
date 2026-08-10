<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageRecommendationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterLeverageRecommendation;
use App\Services\Leverage\LeverageRecommendationLifecycleService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeverageRecommendationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeverageRecommendationLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeverageRecommendationLifecycleService(new MatterBudgetAccessPolicyService);
    }

    private function owner(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
    }

    private function recommendation(Firm $firm): MatterLeverageRecommendation
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            MatterBudget::factory()->forMatter($matter)->create();

            return MatterLeverageRecommendation::factory()->forMatter($matter)->create();
        });
    }

    public function test_acknowledge_transitions_open_to_acknowledged(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $r = $this->recommendation($firm);

        $updated = $this->service->acknowledge($firm, $r, $owner);

        $this->assertSame(MatterLeverageRecommendationStatus::Acknowledged, $updated->status);
        $this->assertSame($owner->id, $updated->acknowledged_by_firm_user_id);
    }

    public function test_dismiss_from_open_transitions_to_dismissed_with_reason(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $r = $this->recommendation($firm);

        $updated = $this->service->dismiss($firm, $r, $owner, 'Not applicable to this matter.');

        $this->assertSame(MatterLeverageRecommendationStatus::Dismissed, $updated->status);
        $this->assertSame('Not applicable to this matter.', $updated->resolution_notes);
    }

    public function test_resolve_from_acknowledged_transitions_to_resolved(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $r = $this->recommendation($firm);
        $acknowledged = $this->service->acknowledge($firm, $r, $owner);

        $resolved = $this->service->resolve($firm, $acknowledged, $owner, 'Reassigned to paralegal.');

        $this->assertSame(MatterLeverageRecommendationStatus::Resolved, $resolved->status);
    }

    public function test_a_dismissed_recommendation_cannot_be_dismissed_again(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $r = $this->recommendation($firm);
        $dismissed = $this->service->dismiss($firm, $r, $owner);

        $this->expectException(\RuntimeException::class);

        $this->service->dismiss($firm, $dismissed, $owner);
    }

    public function test_unauthorized_role_cannot_acknowledge(): void
    {
        $firm = Firm::factory()->create();
        $receptionist = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Receptionist]));
        $r = $this->recommendation($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->acknowledge($firm, $r, $receptionist);
    }

    public function test_cross_firm_acknowledge_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerB = $this->owner($firmB);
        $rA = $this->recommendation($firmA);

        $this->expectException(\RuntimeException::class);

        $this->service->acknowledge($firmA, $rA, $ownerB);
    }

    public function test_mark_stale_transitions_an_open_recommendation(): void
    {
        $firm = Firm::factory()->create();
        $r = $this->recommendation($firm);

        $this->runWithFirmContext($firm, fn () => $this->service->markStale($r));

        $reRead = $this->runWithFirmContext($firm, fn () => $r->fresh()->status);
        $this->assertSame(MatterLeverageRecommendationStatus::Stale, $reRead);
    }

    public function test_mark_stale_never_touches_an_already_resolved_recommendation(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $r = $this->recommendation($firm);
        $resolved = $this->service->resolve($firm, $r, $owner);

        $this->runWithFirmContext($firm, fn () => $this->service->markStale($resolved));

        $reRead = $this->runWithFirmContext($firm, fn () => $resolved->fresh()->status);
        $this->assertSame(MatterLeverageRecommendationStatus::Resolved, $reRead);
    }
}
