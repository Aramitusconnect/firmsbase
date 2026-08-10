<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\BudgetAlertsRelationManager;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAlert;
use App\Models\MatterBudgetAnalysis;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MatterViewBudgetSectionTest — Predictive Matter Budget Alerts, item
 * 17/22. Proves "No Budget Configured" renders for a Matter with no
 * budget (never a fabricated zero), operational figures render for a
 * broad role set, and profitability figures (margin, AR, projected
 * cost) are hidden from a role with only operational visibility.
 */
final class MatterViewBudgetSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function matterWithAnalysis(Firm $firm): Matter
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $budget = MatterBudget::factory()->forMatter($matter)->create();

            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], [
                'firm_id' => $firm->id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 8, 'consumed_percent' => 80]],
                'expenses_by_category_json' => [],
                'total_labor_cost_cents' => 40000,
                'total_expenses_cents' => 0,
                'work_completion_percent' => 60,
                'work_completion_breakdown_json' => [],
                'time_elapsed_percent' => 50,
                'current_margin_percent' => 22,
                'projected_margin_percent' => 18,
                'revenue_outstanding_cents' => 12345,
                'projected_hours_by_role_json' => [],
                'projected_overrun_hours_by_role_json' => [],
                'computed_at' => now(),
            ]);

            return $matter;
        });
    }

    public function test_a_matter_with_no_budget_shows_no_budget_configured(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('No Budget Configured');
    }

    public function test_firm_owner_sees_operational_and_profitability_figures(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->matterWithAnalysis($firm);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Work Completion');
        $response->assertSee('Current Margin');
        $response->assertSee('Projected Margin');
    }

    public function test_paralegal_sees_operational_but_not_profitability_figures(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->matterWithAnalysis($firm);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Work Completion');
        $response->assertDontSee('Current Margin');
        $response->assertDontSee('Projected Margin');
        $response->assertDontSee('AR Remaining');
    }

    public function test_receptionist_sees_no_budget_section_at_all(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $matter = $this->matterWithAnalysis($firm);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertDontSee('Work Completion');
        $response->assertDontSee('Current Margin');
    }

    public function test_budget_alerts_tab_shows_only_this_matters_alerts(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->matterWithAnalysis($firm);
        $matterB = $this->matterWithAnalysis($firm);

        [$alertA, $alertB] = $this->runWithFirmContext($firm, function () use ($matterA, $matterB) {
            $budgetA = MatterBudget::query()->where('matter_id', $matterA->id)->first();
            $budgetB = MatterBudget::query()->where('matter_id', $matterB->id)->first();

            $alertA = MatterBudgetAlert::factory()->forMatter($matterA, $budgetA)->create();
            $alertB = MatterBudgetAlert::factory()->forMatter($matterB, $budgetB)->create();

            return [$alertA, $alertB];
        });

        $this->runWithFirmContext($firm, function () use ($matterA, $alertA, $alertB) {
            $test = Livewire::test(BudgetAlertsRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$alertA]);
            $test->assertCanNotSeeTableRecords([$alertB]);
        });
    }

    public function test_budget_alerts_tab_is_hidden_from_receptionist(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $canView = $this->runWithFirmContext($firm, fn () => BudgetAlertsRelationManager::canViewForRecord($matter, ViewMatter::class));

        $this->assertFalse($canView);
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
