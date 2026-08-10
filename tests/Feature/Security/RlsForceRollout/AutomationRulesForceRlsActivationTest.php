<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\AutomationRule;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AutomationRulesForceRlsActivationTest — Event-Driven Automation
 * Engine pass. Proves automation_rules' permanent FORCE ROW LEVEL
 * SECURITY (2026_11_04_100004) behaves correctly.
 */
class AutomationRulesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRule(Firm $firm): AutomationRule
    {
        return $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create());
    }

    public function test_automation_rules_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'automation_rules'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_automation_rules(): void
    {
        $firm = Firm::factory()->create();
        $this->makeRule($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AutomationRule::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_automation_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeRule($firmA);
        $ruleB = $this->makeRule($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AutomationRule::query()->pluck('id')->all(),
        );

        $this->assertNotContains($ruleB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $rule = $this->makeRule($firm);

        $this->runWithFirmContext($firm, fn () => $rule->update(['enabled' => false]));

        $reRead = $this->runWithFirmContext($firm, fn () => $rule->fresh()->enabled);
        $this->assertFalse($reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_04_100004_prepare_row_level_security_and_force_rls_on_automation_rules_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'automation_rules'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
