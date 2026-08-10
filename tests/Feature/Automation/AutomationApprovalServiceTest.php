<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionRiskLevel;
use App\Enums\AutomationApprovalStatus;
use App\Enums\FirmUserRole;
use App\Models\AutomationActionExecution;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\Automation\AutomationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AutomationApprovalServiceTest — Event-Driven Automation Engine, item
 * 7 (human approval) + item 17 (security). No AutomationActionType
 * registered in this pass is actually classified RequiresApproval (see
 * AutomationActionRiskLevel's own docblock), so — mirroring the
 * drift-simulation technique used elsewhere in this codebase — these
 * tests construct a RequiresReview+Pending-approval AutomationActionExecution
 * directly via the model/factory to exercise the real, tested approval
 * gate: FirmOwner-only, cross-firm denial, and an already-resolved row
 * can never be re-approved or re-rejected. "Automation may not approve
 * itself" — this service is the ONLY writer of approval_status.
 */
class AutomationApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutomationApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutomationApprovalService;
    }

    private function makePendingApproval(Firm $firm): AutomationActionExecution
    {
        return $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create([
            'risk_level' => AutomationActionRiskLevel::RequiresApproval,
            'status' => AutomationActionExecutionStatus::RequiresReview,
            'approval_status' => AutomationApprovalStatus::Pending,
        ]));
    }

    public function test_firm_owner_can_approve_a_pending_action(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $action = $this->makePendingApproval($firm);

        $approved = $this->service->approve($firm, $action, $owner);

        $this->assertSame(AutomationApprovalStatus::Approved, $approved->approval_status);
        // Released back into the claim pool — a Pending row, not
        // RequiresReview, since the very next dispatch tick should pick
        // it up exactly like any other action.
        $this->assertSame(AutomationActionExecutionStatus::Pending, $approved->status);
        $this->assertSame($owner->id, $approved->approved_by_firm_user_id);
    }

    public function test_firm_owner_can_reject_a_pending_action(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $action = $this->makePendingApproval($firm);

        $rejected = $this->service->reject($firm, $action, $owner, 'Not appropriate for this client.');

        $this->assertSame(AutomationApprovalStatus::Rejected, $rejected->approval_status);
        $this->assertSame(AutomationActionExecutionStatus::Failed, $rejected->status);
        $this->assertNotNull($rejected->completed_at);
    }

    public function test_a_non_firm_owner_cannot_approve(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));
        $action = $this->makePendingApproval($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->approve($firm, $action, $attorney);
    }

    public function test_a_non_firm_owner_cannot_reject(): void
    {
        $firm = Firm::factory()->create();
        $billingStaff = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff]));
        $action = $this->makePendingApproval($firm);

        $this->expectException(\RuntimeException::class);

        $this->service->reject($firm, $action, $billingStaff, 'no');
    }

    public function test_cross_firm_approval_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create(['role' => FirmUserRole::FirmOwner]));
        $actionA = $this->makePendingApproval($firmA);

        $this->expectException(\RuntimeException::class);

        $this->service->approve($firmA, $actionA, $ownerB);
    }

    public function test_an_already_approved_action_cannot_be_approved_again(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $action = $this->makePendingApproval($firm);
        $this->service->approve($firm, $action, $owner);

        $fresh = $this->runWithFirmContext($firm, fn () => $action->fresh());

        $this->expectException(\RuntimeException::class);

        $this->service->approve($firm, $fresh, $owner);
    }

    public function test_an_already_rejected_action_cannot_be_approved(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        $action = $this->makePendingApproval($firm);
        $this->service->reject($firm, $action, $owner, 'no');

        $fresh = $this->runWithFirmContext($firm, fn () => $action->fresh());

        $this->expectException(\RuntimeException::class);

        $this->service->approve($firm, $fresh, $owner);
    }

    public function test_an_action_not_awaiting_approval_at_all_cannot_be_approved(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));
        // A perfectly normal AutoAllowed action, never routed through approval.
        $action = $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create());

        $this->expectException(\RuntimeException::class);

        $this->service->approve($firm, $action, $owner);
    }
}
