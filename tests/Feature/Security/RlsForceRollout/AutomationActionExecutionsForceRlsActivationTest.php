<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AutomationActionExecutionsForceRlsActivationTest — Event-Driven
 * Automation Engine pass. Proves automation_action_executions'
 * permanent FORCE ROW LEVEL SECURITY (2026_11_04_100008) behaves
 * correctly.
 */
class AutomationActionExecutionsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeActionExecution(Firm $firm): AutomationActionExecution
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->create();
            $event = DomainEvent::factory()->forFirm($firm)->create();
            $execution = AutomationExecution::factory()->forFirm($firm)->create([
                'automation_rule_id' => $rule->id,
                'domain_event_id' => $event->id,
            ]);

            return AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
            ]);
        });
    }

    public function test_automation_action_executions_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'automation_action_executions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_automation_action_executions(): void
    {
        $firm = Firm::factory()->create();
        $this->makeActionExecution($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AutomationActionExecution::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_automation_action_executions(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeActionExecution($firmA);
        $actionB = $this->makeActionExecution($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AutomationActionExecution::query()->pluck('id')->all(),
        );

        $this->assertNotContains($actionB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $actionExecution = $this->makeActionExecution($firm);

        $this->runWithFirmContext($firm, fn () => $actionExecution->update(['status' => 'succeeded']));

        $reRead = $this->runWithFirmContext($firm, fn () => $actionExecution->fresh()->status->value);
        $this->assertSame('succeeded', $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_04_100008_prepare_row_level_security_and_force_rls_on_automation_action_executions_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'automation_action_executions'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
