<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterBudgetAnalysesForceRlsActivationTest — Predictive Matter
 * Budget Alerts pass. Proves matter_budget_analyses' permanent FORCE
 * ROW LEVEL SECURITY (2026_11_05_100006) behaves correctly.
 */
class MatterBudgetAnalysesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(Firm $firm): MatterBudgetAnalysis
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $budget = MatterBudget::factory()->forMatter($matter)->create();

            return MatterBudgetAnalysis::factory()->forMatter($matter, $budget)->create();
        });
    }

    public function test_matter_budget_analyses_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budget_analyses'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_matter_budget_analyses(): void
    {
        $firm = Firm::factory()->create();
        $this->makeAnalysis($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterBudgetAnalysis::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_budget_analyses(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeAnalysis($firmA);
        $analysisB = $this->makeAnalysis($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterBudgetAnalysis::query()->pluck('id')->all(),
        );

        $this->assertNotContains($analysisB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $analysis = $this->makeAnalysis($firm);

        $this->runWithFirmContext($firm, fn () => $analysis->update(['work_completion_percent' => 42]));

        $reRead = $this->runWithFirmContext($firm, fn () => $analysis->fresh()->work_completion_percent);
        $this->assertSame(42, $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_05_100006_prepare_row_level_security_and_force_rls_on_matter_budget_analyses_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budget_analyses'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
