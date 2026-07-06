<?php

namespace Tests\Feature\Ai\Retrieval;

use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use App\Services\MatterAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Approved decision #3: FirmOwner/Attorney access every matter in
 * their own firm; Paralegal/LegalAssistant/Receptionist/BillingStaff
 * need an active MatterAssignment; removed assignments do not count;
 * no cross-firm access regardless of role.
 */
class MatterAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    public function test_firm_owner_can_access_any_matter_in_their_firm_without_an_assignment(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $ownerFirmUser = $this->makeFirmOwner($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($ownerFirmUser->user, $matter);

        $this->assertTrue($allowed);
    }

    public function test_attorney_can_access_any_matter_in_their_firm_without_an_assignment(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $attorneyFirmUser = $this->makeAttorney($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($attorneyFirmUser->user, $matter);

        $this->assertTrue($allowed);
    }

    public function test_paralegal_without_an_assignment_cannot_access_the_matter(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($paralegalFirmUser->user, $matter);

        $this->assertFalse($allowed);
    }

    public function test_paralegal_with_an_active_assignment_can_access_the_matter(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        MatterAssignment::factory()->forMatter($matter)->forUser($paralegalFirmUser->user)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($paralegalFirmUser->user, $matter);

        $this->assertTrue($allowed);
    }

    public function test_a_removed_assignment_does_not_count(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        MatterAssignment::factory()
            ->forMatter($matter)
            ->forUser($paralegalFirmUser->user)
            ->create(['removed_at' => now()]);

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($paralegalFirmUser->user, $matter);

        $this->assertFalse($allowed);
    }

    public function test_no_user_can_access_another_firms_matter_regardless_of_role(): void
    {
        $firmA = $this->makeAiEntitledFirm();
        $firmB = $this->makeAiEntitledFirm();
        $ownerOfA = $this->makeFirmOwner($firmA);
        $matterInB = Matter::factory()->forFirm($firmB)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessMatter($ownerOfA->user, $matterInB);

        $this->assertFalse($allowed);
    }

    public function test_cross_matter_access_requires_authorization_for_every_matter(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matterA = Matter::factory()->forFirm($firm)->create();
        $matterB = Matter::factory()->forFirm($firm)->create();

        MatterAssignment::factory()->forMatter($matterA)->forUser($paralegalFirmUser->user)->create();
        // No assignment for matterB.

        $allowed = app(MatterAccessPolicyService::class)->canAccessAllMatters($paralegalFirmUser->user, [$matterA, $matterB]);

        $this->assertFalse($allowed);
    }

    public function test_cross_matter_access_succeeds_when_authorized_for_every_matter(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $paralegalFirmUser = $this->makeParalegal($firm);
        $matterA = Matter::factory()->forFirm($firm)->create();
        $matterB = Matter::factory()->forFirm($firm)->create();

        MatterAssignment::factory()->forMatter($matterA)->forUser($paralegalFirmUser->user)->create();
        MatterAssignment::factory()->forMatter($matterB)->forUser($paralegalFirmUser->user)->create();

        $allowed = app(MatterAccessPolicyService::class)->canAccessAllMatters($paralegalFirmUser->user, [$matterA, $matterB]);

        $this->assertTrue($allowed);
    }
}
