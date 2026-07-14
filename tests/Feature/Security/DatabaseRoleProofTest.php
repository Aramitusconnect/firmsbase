<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DatabaseRoleProofTest — Agent 1C.
 *
 * Prior mission documentation
 * (rls-checkpoints/incidents/test-db-wipe-after-checkpoint-21/
 * test-harness-containment-report.md, "Dedicated role and ACLs") only
 * ASSERTS that the runtime database role this application/test suite
 * connects as (currently `rls_test_runner_39a3l` — see
 * tests/bootstrap-verify-test-database.php) is
 * `NOSUPERUSER`/`NOBYPASSRLS`/etc. Multiple individual FORCE-activation
 * test files (e.g. TimelineEventsForceRlsActivationTest,
 * SecurityEventsForceRlsActivationTest, and the
 * force_rls_on_timeline_events_table migration's own docblock) repeat
 * this claim in prose ("confirmed non-superuser, rolbypassrls=false")
 * but no test anywhere in this repository had, until now, independently
 * PROVEN it against `pg_roles` as executable test code. This file is
 * that proof, plus three adjacent proofs that make the whole "FORCE RLS
 * actually protects us because the connected role cannot bypass it"
 * argument airtight rather than merely plausible:
 *
 *   1. The connected role is not a Postgres superuser.
 *   2. The connected role does not have BYPASSRLS.
 *   3. Every table currently FORCE-RLS-enabled (per
 *      RowLevelSecurityCoverageMappingService::forcedTables(), itself
 *      derived from every database/migrations/*_force_rls_on_*_table.php
 *      migration — see that service for why this is not a hardcoded
 *      list) genuinely has both relrowsecurity AND relforcerowsecurity
 *      set in pg_class. FORCE is precisely what neutralizes the
 *      table-owner-bypass exemption Postgres would otherwise grant this
 *      role if it happens to own these tables (it does — it ran the
 *      migrations that created them; see the Phase 1 preparation
 *      migration's own docblock) — so relforcerowsecurity=true on every
 *      forced table is the load-bearing fact, independent of ownership.
 *   4. No stray, undocumented, wide-open policy exists on any forced
 *      table: a representative sample (chosen to cover this
 *      codebase's three distinct forced-policy shapes — see below) is
 *      checked for an EXACT policy-name match against what each
 *      shape's own migration source declares, and every forced table
 *      (not just the sample) is swept for any policy whose USING/WITH
 *      CHECK clause is unconditionally true or does not reference
 *      current_setting(...) at all — the signature of a stray
 *      permissive policy that would silently defeat tenant isolation
 *      regardless of FORCE.
 *   5. Missing-context denial is real at the raw-SQL level, not just
 *      through Eloquent's own global scope: three representative forced
 *      tables return zero rows via a bare DB::table(...)->count() when
 *      no app.current_firm_id (and, for firm_users, no
 *      app.current_user_id) session variable is set — matching exactly
 *      what each table's own existing per-table
 *      test_missing_tenant_context_cannot_read_* test already expects.
 *
 * This file does not re-derive or re-prove any single table's own
 * specific cross-firm read/write isolation shape (that is each
 * table's own ...ForceRlsActivationTest's job) — it proves the
 * connection-role and policy-hygiene guarantees that make every one of
 * those per-table proofs actually meaningful.
 */
class DatabaseRoleProofTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sample forced tables chosen to cover every distinct forced-policy
     * shape this codebase currently uses, each verified directly
     * against its own migration source (not re-run here, only
     * cross-referenced):
     *
     *   - clients: a single, untouched Phase 2 preparation policy
     *     (database/migrations/2026_07_05_600024_extend_row_level_
     *     security_to_phase_2_tenant_tables.php) — "clients_tenant_isolation".
     *   - timeline_events: the same single-policy shape as clients —
     *     its own FORCE migration (2026_08_25_930033_force_rls_on_
     *     timeline_events_table.php) deliberately issues no
     *     DROP POLICY/CREATE POLICY at all, per that migration's own
     *     docblock — "timeline_events_tenant_isolation".
     *   - firm_users: the original tenant-isolation policy PLUS a
     *     separate, additive, FOR SELECT-only self-lookup policy
     *     (2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_
     *     policy.php) — "firm_users_tenant_isolation",
     *     "firm_users_self_lookup".
     *   - security_events: the original policy rewritten to a FOR
     *     SELECT-only nullable-firm_id shape PLUS a separate FOR
     *     INSERT-only write policy (2026_08_25_930034_force_rls_on_
     *     security_events_table.php) — "security_events_tenant_isolation",
     *     "security_events_platform_write".
     *   - backup_restore_tests and health_checks: the original policy
     *     DROPPED and replaced with a FOR SELECT read policy plus a FOR
     *     ALL write policy (2026_08_25_930027_force_rls_on_
     *     backup_restore_tests_table.php and 2026_08_25_930028_
     *     force_rls_on_health_checks_table.php, the same shape shared by
     *     incident_events/maintenance_windows/notification_templates/
     *     pilot_feedback_items) — "{table}_tenant_read",
     *     "{table}_tenant_write".
     *
     * @var array<string, array<int, string>>
     */
    private const SAMPLE_TABLE_EXPECTED_POLICIES = [
        'clients' => ['clients_tenant_isolation'],
        'timeline_events' => ['timeline_events_tenant_isolation'],
        'firm_users' => ['firm_users_self_lookup', 'firm_users_tenant_isolation'],
        'security_events' => ['security_events_platform_write', 'security_events_tenant_isolation'],
        'backup_restore_tests' => ['backup_restore_tests_tenant_read', 'backup_restore_tests_tenant_write'],
        'health_checks' => ['health_checks_tenant_read', 'health_checks_tenant_write'],
    ];

    public function test_the_connected_runtime_role_is_not_a_postgres_superuser(): void
    {
        $row = DB::selectOne('select rolsuper from pg_roles where rolname = current_user');

        $this->assertNotNull($row, 'current_user must resolve to a real row in pg_roles.');
        $this->assertFalse(
            (bool) $row->rolsuper,
            'The runtime database role must NOT be a Postgres superuser — a superuser bypasses row-level security entirely, regardless of FORCE, making every FORCE RLS activation in this codebase meaningless.'
        );
    }

    public function test_the_connected_runtime_role_does_not_have_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');

        $this->assertNotNull($row, 'current_user must resolve to a real row in pg_roles.');
        $this->assertFalse(
            (bool) $row->rolbypassrls,
            'The runtime database role must NOT have BYPASSRLS — this attribute alone (independent of superuser status) lets a role skip every RLS policy on every table, including FORCE-protected ones.'
        );
    }

    public function test_every_forced_table_has_row_level_security_enabled_and_forced(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $forcedTables = $coverage->forcedTables();

        $this->assertNotEmpty($forcedTables, 'Expected at least one FORCE-activation migration to exist by this point in the rollout.');

        foreach ($forcedTables as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} (reported as forced by RowLevelSecurityCoverageMappingService::forcedTables()) not found in pg_class.");

            $this->assertTrue(
                (bool) $row->relrowsecurity,
                "{$table} must have row-level security enabled."
            );

            // The real proof: FORCE is what neutralizes the table-owner
            // bypass exemption. Ownership itself is irrelevant to this
            // assertion — whether or not the runtime role happens to
            // own {$table}, relforcerowsecurity=true is what guarantees
            // its own policies are still evaluated against it.
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must have permanent FORCE ROW LEVEL SECURITY active — without FORCE, a role that owns this table (as the runtime role owns every table it migrated) is exempt from its own RLS policies."
            );
        }
    }

    public function test_sample_forced_tables_have_exactly_their_documented_policy_set(): void
    {
        foreach (self::SAMPLE_TABLE_EXPECTED_POLICIES as $table => $expectedPolicies) {
            $rows = DB::select('select policyname from pg_policies where tablename = ? order by policyname', [$table]);

            $actualPolicies = array_values(array_map(static fn ($row) => $row->policyname, $rows));

            sort($actualPolicies);
            $sortedExpected = $expectedPolicies;
            sort($sortedExpected);

            $this->assertSame(
                $sortedExpected,
                $actualPolicies,
                "{$table} must have exactly its documented policy set (".implode(', ', $sortedExpected).') — an extra or missing policy here means either a stray undocumented policy was added, or a documented one was silently dropped/renamed.'
            );
        }
    }

    public function test_no_forced_table_has_an_unconditionally_permissive_policy(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $rows = DB::select('select policyname, qual, with_check from pg_policies where tablename = ?', [$table]);

            $this->assertNotEmpty($rows, "{$table} is reported as FORCE-enabled but has zero pg_policies rows — FORCE with no policy at all means every row is denied, which is a fail-closed state but almost certainly not the intended one; investigate before trusting this table's isolation.");

            foreach ($rows as $policy) {
                $clauses = array_filter([$policy->qual, $policy->with_check], static fn ($clause) => $clause !== null);

                $this->assertNotEmpty(
                    $clauses,
                    "{$table}'s policy '{$policy->policyname}' has neither a USING nor a WITH CHECK clause — this is an unconditionally permissive (always-allow) policy, exactly the stray/undocumented shape this sweep exists to catch."
                );

                foreach ($clauses as $clause) {
                    $normalized = strtolower(trim($clause));

                    $this->assertNotSame(
                        'true',
                        $normalized,
                        "{$table}'s policy '{$policy->policyname}' has a bare 'true' clause — an unconditionally permissive policy that would grant unrestricted access regardless of FORCE ROW LEVEL SECURITY."
                    );

                    $this->assertStringContainsString(
                        'current_setting',
                        $normalized,
                        "{$table}'s policy '{$policy->policyname}' has a clause that does not reference current_setting(...) at all (".$clause.'), meaning it does not condition access on any tenant-context session variable — a stray permissive policy of exactly the kind this sweep exists to catch.'
                    );
                }
            }
        }
    }

    public function test_missing_tenant_context_returns_zero_rows_via_raw_query_for_clients(): void
    {
        $firm = Firm::factory()->create();
        Client::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(
            0,
            DB::table('clients')->count(),
            'A raw (non-Eloquent) query against clients with no app.current_firm_id session variable set must return zero rows — this proves denial is enforced by the database policy itself, not by an application-level Eloquent global scope that a raw query would bypass.'
        );
    }

    public function test_missing_tenant_context_returns_zero_rows_via_raw_query_for_documents(): void
    {
        $firm = Firm::factory()->create();
        Document::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(
            0,
            DB::table('documents')->count(),
            'A raw (non-Eloquent) query against documents with no app.current_firm_id session variable set must return zero rows — same DB-level enforcement proof as clients above.'
        );
    }

    public function test_missing_tenant_context_returns_zero_rows_via_raw_query_for_firm_users(): void
    {
        $firm = Firm::factory()->create();
        FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(
            0,
            DB::table('firm_users')->count(),
            'A raw (non-Eloquent) query against firm_users with neither app.current_firm_id nor app.current_user_id session variables set must return zero rows — firm_users carries an additional FOR SELECT-only self-lookup policy (firm_users_self_lookup), but with no app.current_user_id set either, that policy clause is also never-true, so the row remains fully denied.'
        );
    }
}
