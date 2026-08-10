<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAlert;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterBudgetAlertsForceRlsActivationTest — Predictive Matter Budget
 * Alerts pass. Proves matter_budget_alerts' permanent FORCE ROW LEVEL
 * SECURITY (2026_11_05_100008) behaves correctly.
 */
class MatterBudgetAlertsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlert(Firm $firm): MatterBudgetAlert
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $budget = MatterBudget::factory()->forMatter($matter)->create();

            return MatterBudgetAlert::factory()->forMatter($matter, $budget)->create();
        });
    }

    public function test_matter_budget_alerts_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budget_alerts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_matter_budget_alerts(): void
    {
        $firm = Firm::factory()->create();
        $this->makeAlert($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterBudgetAlert::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_budget_alerts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeAlert($firmA);
        $alertB = $this->makeAlert($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterBudgetAlert::query()->pluck('id')->all(),
        );

        $this->assertNotContains($alertB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $alert = $this->makeAlert($firm);

        $this->runWithFirmContext($firm, fn () => $alert->update(['resolved_at' => now()]));

        $reRead = $this->runWithFirmContext($firm, fn () => $alert->fresh()->resolved_at);
        $this->assertNotNull($reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_05_100008_prepare_row_level_security_and_force_rls_on_matter_budget_alerts_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budget_alerts'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
