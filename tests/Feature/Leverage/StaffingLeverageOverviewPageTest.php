<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\StaffingLeverageOverviewPage;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * StaffingLeverageOverviewPageTest — a real Livewire boot/registration
 * smoke test (mirrors AccountingOverviewPageTest's own shape) proving
 * the Firm-level Staffing & Leverage overview page actually renders,
 * that an operational-only role never sees labor-cost/margin figures,
 * and that a Receptionist has no access to the page at all.
 */
final class StaffingLeverageOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function matterWithLeverageData(Firm $firm): Matter
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $budget = MatterBudget::factory()->forMatter($matter)->create();

            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], [
                'firm_id' => $firm->id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => ['attorney' => ['expected' => 5, 'actual' => 10, 'consumed_percent' => 200]],
                'expenses_by_category_json' => [],
                'total_labor_cost_cents' => 0,
                'cost_by_role_cents_json' => ['attorney' => 150000],
                'total_expenses_cents' => 0,
                'current_margin_percent' => 12,
                'projected_margin_percent' => 8,
                'work_completion_percent' => 50,
                'work_completion_breakdown_json' => [],
                'projected_hours_by_role_json' => [],
                'projected_overrun_hours_by_role_json' => [],
                'computed_at' => now(),
            ]);

            return $matter;
        });
    }

    public function test_the_page_renders_successfully_for_a_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->matterWithLeverageData($firm);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(StaffingLeverageOverviewPage::class);
            $test->assertSuccessful();
        });
    }

    public function test_a_paralegal_can_view_the_page_but_never_sees_margin_figures(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->matterWithLeverageData($firm);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(StaffingLeverageOverviewPage::class);
            $test->assertSuccessful();
            $test->assertDontSee('Lowest projected margin');
        });
    }

    public function test_a_receptionist_cannot_access_the_page(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $canAccess = $this->runWithFirmContext($firm, fn () => StaffingLeverageOverviewPage::canAccess());

        $this->assertFalse($canAccess);
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
