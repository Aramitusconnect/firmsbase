<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\TaskCategoryRoleExpectation;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TaskCategoryRoleExpectationsForceRlsActivationTest — Leverage Ratio
 * Optimizer pass. Proves task_category_role_expectations' permanent
 * FORCE ROW LEVEL SECURITY (2026_11_06_100003) behaves correctly.
 */
class TaskCategoryRoleExpectationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makeExpectation(Firm $firm): TaskCategoryRoleExpectation
    {
        return $this->runWithFirmContext($firm, fn () => TaskCategoryRoleExpectation::factory()->forFirm($firm)->create());
    }

    public function test_task_category_role_expectations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'task_category_role_expectations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_task_category_role_expectations(): void
    {
        $firm = Firm::factory()->create();
        $this->makeExpectation($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TaskCategoryRoleExpectation::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_task_category_role_expectations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makeExpectation($firmA);
        $expectationB = $this->makeExpectation($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TaskCategoryRoleExpectation::query()->pluck('id')->all(),
        );

        $this->assertNotContains($expectationB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $expectation = $this->makeExpectation($firm);

        $this->runWithFirmContext($firm, fn () => $expectation->update(['notes' => 'updated']));

        $reRead = $this->runWithFirmContext($firm, fn () => $expectation->fresh()->notes);
        $this->assertSame('updated', $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_06_100003_prepare_row_level_security_and_force_rls_on_task_category_role_expectations_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'task_category_role_expectations'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
