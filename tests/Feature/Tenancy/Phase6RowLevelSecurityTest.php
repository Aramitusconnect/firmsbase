<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifies the Phase 6 RLS extension migration did what it claims —
 * for exactly the 3 tables approved for Phase 6 RLS (seat_allocations,
 * template_upgrade_previews, template_upgrade_logs) — and, just as
 * importantly, verifies RLS was deliberately NOT enabled on the
 * platform-level/global/account-attribution tables that were excluded
 * by design. Mirrors Phase 1's RowLevelSecurityPreparationTest pattern
 * exactly (same PREPARATION-not-enforcement caveat applies).
 */
class Phase6RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function rlsTableProvider(): array
    {
        return [
            ['seat_allocations'],
            ['template_upgrade_previews'],
            ['template_upgrade_logs'],
        ];
    }

    public static function excludedTableProvider(): array
    {
        return [
            ['plans'],
            ['plan_modules'],
            ['plan_limits'],
            ['org_licenses'],
            ['seat_pools'],
            ['license_events'],
            ['platform_subscriptions'],
            ['platform_invoices'],
            ['platform_invoice_lines'],
            ['platform_payments'],
            ['platform_refunds'],
            ['platform_payment_attempts'],
            ['platform_billing_events'],
            ['usage_rollups'],
        ];
    }

    #[DataProvider('rlsTableProvider')]
    public function test_row_level_security_is_enabled(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table]
        );

        $this->assertNotNull($row, "Table {$table} not found in pg_class.");
        $this->assertTrue((bool) $row->relrowsecurity, "RLS is not enabled on {$table}.");
        $this->assertFalse((bool) $row->relforcerowsecurity, "FORCE ROW LEVEL SECURITY unexpectedly enabled on {$table}.");
    }

    #[DataProvider('rlsTableProvider')]
    public function test_tenant_isolation_policy_exists(string $table): void
    {
        $policy = DB::selectOne(
            'select polname, pg_get_expr(polqual, polrelid) as using_expression '
            .'from pg_policy where polrelid = ?::regclass',
            [$table]
        );

        $this->assertNotNull($policy, "No RLS policy found on {$table}.");
        $this->assertSame("{$table}_tenant_isolation", $policy->polname);
        $this->assertStringContainsString('firm_id', $policy->using_expression);
        $this->assertStringContainsString('app.current_firm_id', $policy->using_expression);
    }

    #[DataProvider('excludedTableProvider')]
    public function test_row_level_security_was_deliberately_not_applied(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity from pg_class where relname = ?',
            [$table]
        );

        $this->assertNotNull($row, "Table {$table} not found in pg_class.");
        $this->assertFalse(
            (bool) $row->relrowsecurity,
            "{$table} unexpectedly has RLS enabled — this table is platform/account-scoped, not "
            .'firm-scoped, and was deliberately excluded from Phase 6 RLS.'
        );
    }
}
