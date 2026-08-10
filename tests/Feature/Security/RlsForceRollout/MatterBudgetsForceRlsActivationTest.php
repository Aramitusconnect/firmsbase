<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\MatterBudget;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterBudgetsForceRlsActivationTest — Predictive Matter Budget
 * Alerts pass. Proves matter_budgets' permanent FORCE ROW LEVEL
 * SECURITY (2026_11_05_100004) behaves correctly.
 */
class MatterBudgetsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBudget(Firm $firm): MatterBudget
    {
        return $this->runWithFirmContext($firm, fn () => MatterBudget::factory()->forFirm($firm)->create());
    }

    public function test_matter_budgets_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budgets'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_matter_budgets(): void
    {
        $firm = Firm::factory()->create();
        $this->makeBudget($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterBudget::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_budgets(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeBudget($firmA);
        $budgetB = $this->makeBudget($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterBudget::query()->pluck('id')->all(),
        );

        $this->assertNotContains($budgetB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, fn () => MatterBudget::factory()->forFirm($firm)->create(['version' => 2]));

        $reRead = $this->runWithFirmContext($firm, fn () => $created->fresh()->version);
        $this->assertSame(2, $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_05_100004_prepare_row_level_security_and_force_rls_on_matter_budgets_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budgets'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
