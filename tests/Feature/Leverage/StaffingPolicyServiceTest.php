<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\TaskWorkCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\Leverage\StaffingPolicyService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffingPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffingPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StaffingPolicyService(new MatterBudgetAccessPolicyService);
    }

    private function owner(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
    }

    public function test_setting_an_expectation_creates_a_new_row(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $expectation = $this->service->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal, FirmUserRole::LegalAssistant]);

        $this->assertSame(['paralegal', 'legal_assistant'], $expectation->recommended_roles_json);
    }

    public function test_setting_an_expectation_for_the_same_category_twice_updates_the_same_row(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $first = $this->service->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal]);
        $second = $this->service->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::LegalAssistant]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(['legal_assistant'], $second->recommended_roles_json);
    }

    public function test_a_category_with_no_configured_expectation_returns_null(): void
    {
        $firm = Firm::factory()->create();

        $result = $this->runWithFirmContext($firm, fn () => $this->service->recommendedRolesFor($firm, TaskWorkCategory::CourtAppearance));

        $this->assertNull($result);
    }

    public function test_recommended_roles_for_returns_the_configured_roles(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $this->service->setExpectation($firm, $owner, TaskWorkCategory::CourtAppearance, [FirmUserRole::Attorney]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->recommendedRolesFor($firm, TaskWorkCategory::CourtAppearance));

        $this->assertSame([FirmUserRole::Attorney], $result);
    }

    public function test_unauthorized_role_cannot_set_an_expectation(): void
    {
        $firm = Firm::factory()->create();
        $paralegal = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal]));

        $this->expectException(\RuntimeException::class);

        $this->service->setExpectation($firm, $paralegal, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal]);
    }

    public function test_at_least_one_recommended_role_is_required(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, []);
    }

    public function test_removing_an_expectation_deletes_the_row(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->owner($firm);
        $expectation = $this->service->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal]);

        $this->service->remove($firm, $expectation, $owner);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->recommendedRolesFor($firm, TaskWorkCategory::DocumentFollowUp));
        $this->assertNull($result);
    }
}
