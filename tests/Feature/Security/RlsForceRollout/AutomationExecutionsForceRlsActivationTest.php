<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AutomationExecutionsForceRlsActivationTest — Event-Driven Automation
 * Engine pass. Proves automation_executions' permanent FORCE ROW LEVEL
 * SECURITY (2026_11_04_100006) behaves correctly.
 */
class AutomationExecutionsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeExecution(Firm $firm): AutomationExecution
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $rule = AutomationRule::factory()->forFirm($firm)->create();
            $event = DomainEvent::factory()->forFirm($firm)->create();

            return AutomationExecution::factory()->forFirm($firm)->create([
                'automation_rule_id' => $rule->id,
                'domain_event_id' => $event->id,
            ]);
        });
    }

    public function test_automation_executions_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'automation_executions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_automation_executions(): void
    {
        $firm = Firm::factory()->create();
        $this->makeExecution($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AutomationExecution::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_automation_executions(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeExecution($firmA);
        $executionB = $this->makeExecution($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AutomationExecution::query()->pluck('id')->all(),
        );

        $this->assertNotContains($executionB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $execution = $this->makeExecution($firm);

        $this->runWithFirmContext($firm, fn () => $execution->update(['status' => 'completed']));

        $reRead = $this->runWithFirmContext($firm, fn () => $execution->fresh()->status->value);
        $this->assertSame('completed', $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_04_100006_prepare_row_level_security_and_force_rls_on_automation_executions_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'automation_executions'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
