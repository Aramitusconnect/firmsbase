<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifies the RLS PREPARATION migration did what it claims — RLS
 * enabled + a firm_id-matching policy exists — for every tenant-owned
 * table. Does NOT test enforcement (zero-rows-without-context), because
 * enforcement is intentionally not active yet: FORCE ROW LEVEL SECURITY
 * is not set, so the table-owner role (the app's own connection in
 * this setup) is exempt from these policies by Postgres's own default
 * behavior. See the migration's doc comment for the follow-up gate
 * ("Phase 1 RLS Enforcement Activation") that turns enforcement on.
 *
 * Uses PHPUnit attribute-based data providers (#[DataProvider(...)],
 * static provider methods), NOT the legacy @dataProvider docblock
 * annotation. The docblock style is what caused an ArgumentCountError
 * in the previous rebuild attempt under the PHPUnit version paired
 * with Laravel 13 — that annotation form is deprecated/unsupported
 * there and providers must be resolved via the attribute instead.
 */
class RowLevelSecurityPreparationTest extends TestCase
{
    use RefreshDatabase;

    public static function tableProvider(): array
    {
        return [
            ['firm_settings'],
            ['firm_users'],
            ['security_events'],
            ['firm_licenses'],
            ['firm_entitlements'],
            ['firm_entitlement_events'],
            ['activation_checklists'],
            ['tenant_encryption_keys'],
            ['client_communication_preferences'],
            ['communication_consents'],
            ['communication_consent_events'],
        ];
    }

    #[DataProvider('tableProvider')]
    public function test_row_level_security_is_enabled(string $table): void
    {
        $row = DB::selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
            [$table]
        );

        $this->assertNotNull($row, "Table {$table} not found in pg_class.");
        $this->assertTrue((bool) $row->relrowsecurity, "RLS is not enabled on {$table}.");

        // Section 39A-3B (a later, distinct staged-FORCE-activation
        // branch) legitimately activated permanent FORCE ROW LEVEL
        // SECURITY on firm_users — see
        // database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php.
        // Every other Phase 1 table here remains prepared-but-not-forced.
        if ($table === 'firm_users') {
            $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_users must have permanent FORCE ROW LEVEL SECURITY active.');

            return;
        }

        $this->assertFalse(
            (bool) $row->relforcerowsecurity,
            "FORCE ROW LEVEL SECURITY unexpectedly enabled on {$table} — this must only happen "
            .'as part of the dedicated Phase 1 RLS Enforcement Activation change, together with '
            .'session-variable middleware and a full regression pass.'
        );
    }

    #[DataProvider('tableProvider')]
    public function test_tenant_isolation_policy_exists(string $table): void
    {
        // firm_users now carries a second policy (firm_users_self_lookup,
        // added by the 2026_08_10_900001 migration) alongside the
        // tenant-isolation one, and pg_policy has no default row order —
        // filter by polname explicitly rather than relying on which row
        // Postgres happens to return first.
        $policy = DB::selectOne(
            'select polname, pg_get_expr(polqual, polrelid) as using_expression '
            .'from pg_policy where polrelid = ?::regclass and polname = ?',
            [$table, "{$table}_tenant_isolation"]
        );

        $this->assertNotNull($policy, "No RLS policy found on {$table}.");
        $this->assertSame("{$table}_tenant_isolation", $policy->polname);
        $this->assertStringContainsString('firm_id', $policy->using_expression);
        $this->assertStringContainsString('app.current_firm_id', $policy->using_expression);
    }
}
