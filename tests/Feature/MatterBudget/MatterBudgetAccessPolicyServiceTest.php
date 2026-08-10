<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MatterBudgetAccessPolicyServiceTest — Predictive Matter Budget
 * Alerts, item 21/22. Proves the two-tier visibility split (operational
 * vs profitability) is exactly what this feature's own docblocks claim
 * — the only three roles named for "budget/profitability visibility"
 * (FirmOwner, Attorney, BillingStaff) can see internal-cost/margin
 * figures; Paralegal/LegalAssistant see operational data only;
 * Receptionist sees neither.
 */
class MatterBudgetAccessPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterBudgetAccessPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterBudgetAccessPolicyService;
    }

    public function test_only_firm_owner_attorney_and_billing_staff_can_view_profitability(): void
    {
        $this->assertTrue($this->service->canViewProfitability(FirmUserRole::FirmOwner));
        $this->assertTrue($this->service->canViewProfitability(FirmUserRole::Attorney));
        $this->assertTrue($this->service->canViewProfitability(FirmUserRole::BillingStaff));

        $this->assertFalse($this->service->canViewProfitability(FirmUserRole::Paralegal));
        $this->assertFalse($this->service->canViewProfitability(FirmUserRole::LegalAssistant));
        $this->assertFalse($this->service->canViewProfitability(FirmUserRole::Receptionist));
    }

    public function test_paralegal_and_legal_assistant_can_view_operational_budget_but_not_profitability(): void
    {
        $this->assertTrue($this->service->canViewOperationalBudget(FirmUserRole::Paralegal));
        $this->assertTrue($this->service->canViewOperationalBudget(FirmUserRole::LegalAssistant));

        $this->assertFalse($this->service->canViewProfitability(FirmUserRole::Paralegal));
        $this->assertFalse($this->service->canViewProfitability(FirmUserRole::LegalAssistant));
    }

    public function test_receptionist_can_view_neither_operational_nor_profitability_data(): void
    {
        $this->assertFalse($this->service->canViewOperationalBudget(FirmUserRole::Receptionist));
        $this->assertFalse($this->service->canViewProfitability(FirmUserRole::Receptionist));
    }

    public function test_every_role_that_can_view_profitability_can_also_view_operational_data(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            if ($this->service->canViewProfitability($role)) {
                $this->assertTrue(
                    $this->service->canViewOperationalBudget($role),
                    "Role {$role->value} can view profitability but not operational data — profitability should always be a superset."
                );
            }
        }
    }

    public function test_template_management_and_budget_revision_share_the_same_role_ceiling(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $this->assertSame(
                $this->service->canManageTemplates($role),
                $this->service->canReviseMatterBudget($role),
                "Role {$role->value}: template management and budget revision authorization diverged unexpectedly."
            );
        }
    }
}
