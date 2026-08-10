<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LeverageMatterUiPrivacyTest — Leverage Ratio Optimizer, item 27/28.
 * Mirrors MatterViewBudgetSectionTest's own privacy proof exactly,
 * against the new "Staffing & Leverage" section instead: operational
 * staffing figures (hours, shares, task distribution, open
 * recommendations) render for a broad role set, while labor-cost and
 * margin figures — the only page path to EmployeeRate.cost_rate_cents-
 * derived numbers — are hidden from a role with only operational
 * visibility. This is the executable proof item 28 explicitly demands
 * ("test this specifically").
 */
final class LeverageMatterUiPrivacyTest extends TestCase
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
            $budget = MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => ['attorney' => 5, 'paralegal' => 15],
            ]);

            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], [
                'firm_id' => $firm->id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => [
                    'attorney' => ['expected' => 5, 'actual' => 10, 'consumed_percent' => 200],
                    'paralegal' => ['expected' => 15, 'actual' => 2, 'consumed_percent' => 13],
                ],
                'expenses_by_category_json' => [],
                'total_labor_cost_cents' => 0,
                'cost_by_role_cents_json' => ['attorney' => 150000, 'paralegal' => 11000],
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

    public function test_a_matter_with_no_leverage_data_shows_insufficient_data(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Insufficient staffing data');
    }

    public function test_firm_owner_sees_operational_and_profitability_leverage_figures(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->matterWithLeverageData($firm);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Attorney Share');
        $response->assertSee('Labor Cost by Role');
        $response->assertSee('Average Cost / Recorded Hour');
        $response->assertSee('$1,500.00');
        $response->assertSee('$110.00');
    }

    public function test_paralegal_sees_operational_but_not_labor_cost_or_margin_figures(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->matterWithLeverageData($firm);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Staffing & Leverage');
        $response->assertSee('Attorney Share');
        $response->assertSee('Recorded Hours by Role');
        $response->assertDontSee('Labor Cost by Role');
        $response->assertDontSee('Average Cost / Recorded Hour');
        $response->assertDontSee('$1,500.00');
        $response->assertDontSee('$110.00');
        $response->assertDontSee('Current Margin');
    }

    public function test_legal_assistant_sees_operational_but_not_labor_cost_or_margin_figures(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::LegalAssistant);
        $matter = $this->matterWithLeverageData($firm);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Attorney Share');
        $response->assertDontSee('Labor Cost by Role');
        $response->assertDontSee('$1,500.00');
    }

    public function test_receptionist_sees_no_staffing_and_leverage_section_at_all(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $matter = $this->matterWithLeverageData($firm);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertDontSee('Staffing & Leverage');
        $response->assertDontSee('Attorney Share');
        $response->assertDontSee('Labor Cost by Role');
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
