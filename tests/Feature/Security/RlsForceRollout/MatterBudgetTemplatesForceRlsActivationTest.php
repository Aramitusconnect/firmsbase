<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\MatterBudgetTemplate;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterBudgetTemplatesForceRlsActivationTest — Predictive Matter
 * Budget Alerts pass. Proves matter_budget_templates' permanent FORCE
 * ROW LEVEL SECURITY (2026_11_05_100002) behaves correctly.
 */
class MatterBudgetTemplatesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTemplate(Firm $firm): MatterBudgetTemplate
    {
        return $this->runWithFirmContext($firm, fn () => MatterBudgetTemplate::factory()->forFirm($firm)->create());
    }

    public function test_matter_budget_templates_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budget_templates'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_matter_budget_templates(): void
    {
        $firm = Firm::factory()->create();
        $this->makeTemplate($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterBudgetTemplate::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_budget_templates(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeTemplate($firmA);
        $templateB = $this->makeTemplate($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterBudgetTemplate::query()->pluck('id')->all(),
        );

        $this->assertNotContains($templateB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $template = $this->makeTemplate($firm);

        $this->runWithFirmContext($firm, fn () => $template->update(['active' => false]));

        $reRead = $this->runWithFirmContext($firm, fn () => $template->fresh()->active);
        $this->assertFalse($reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_05_100002_prepare_row_level_security_and_force_rls_on_matter_budget_templates_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_budget_templates'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
