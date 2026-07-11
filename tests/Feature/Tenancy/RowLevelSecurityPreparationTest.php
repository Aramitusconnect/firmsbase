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
        // Section 39A-3K (this batch, a later, distinct staged-FORCE-
        // activation branch) legitimately activated permanent FORCE ROW
        // LEVEL SECURITY on client_communication_preferences — see
        // database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php.
        //
        // GAP FOUND AND FIXED during Section 39A-3L, Checkpoint 4's own
        // audit (not caused by Checkpoint 4 — discovered here): Section
        // 39A-3L, Checkpoint 2, Table Phase C legitimately activated
        // permanent FORCE ROW LEVEL SECURITY on activation_checklists
        // (see
        // database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php)
        // but this exception list was never updated to reflect that —
        // meaning this test silently asserted the WRONG expectation for
        // activation_checklists (that it remained un-forced) ever since
        // Checkpoint 2 landed. Added here, alongside firm_entitlements
        // (Section 39A-3L, Checkpoint 4's own table — see
        // database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php),
        // which is why this fix landed in the same pass as the new
        // table's own checkpoint.
        //
        // Section 39A-3L, Checkpoint 5, Table Phase C (this repo's
        // twenty-third staged FORCE activation batch) legitimately
        // activated permanent FORCE ROW LEVEL SECURITY on
        // firm_entitlement_events too — see
        // database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php.
        // Added to the exception list in the same pass as this
        // checkpoint's own table, following the exact same pattern as
        // Checkpoint 4's own fix above.
        //
        // Section 39A-3L, Checkpoint 11, Table Phase B legitimately
        // activated permanent FORCE ROW LEVEL SECURITY on
        // communication_consents — see
        // database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php.
        // Added to the exception list in the SAME commit as that
        // migration, per this file's own docblock lesson above (a table
        // forced without updating this list going silently red for a
        // whole cycle) — not deferred to a later pass.
        //
        // Section 39A-3L, Checkpoint 12, Table Phase B legitimately
        // activated permanent FORCE ROW LEVEL SECURITY on
        // communication_consent_events — see
        // database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php.
        // Added to the exception list in the SAME commit as that
        // migration, again per this file's own docblock lesson above.
        //
        // Section 39A-3L, Checkpoint 16, Table Phase B legitimately
        // activated permanent FORCE ROW LEVEL SECURITY on
        // tenant_encryption_keys — see
        // database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php.
        // Added to the exception list in the SAME commit as that
        // migration, again per this file's own docblock lesson above.
        //
        // Every other Phase 1 table here remains prepared-but-not-forced.
        if (in_array($table, ['firm_users', 'client_communication_preferences', 'activation_checklists', 'firm_entitlements', 'firm_entitlement_events', 'communication_consents', 'communication_consent_events', 'tenant_encryption_keys'], true)) {
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must have permanent FORCE ROW LEVEL SECURITY active.");

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
