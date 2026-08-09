<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingPeriodsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_periods_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_periods'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_accounting_periods(): void
    {
        $firm = Firm::factory()->create();
        AccountingPeriod::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_accounting_periods(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('accounting_periods')->insert([
            'firm_id' => $firm->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Closed->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_accounting_periods(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        AccountingPeriod::factory()->forFirm($firmA)->create();
        $periodB = AccountingPeriod::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AccountingPeriod::query()->pluck('id')->all(),
        );

        $this->assertNotContains($periodB->id, $visibleIds);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_30_100002_prepare_row_level_security_and_force_rls_on_accounting_periods_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_periods'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
