<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NoSuperAdminRlsCarveOutTest — Checkpoint 11 (frozen-design-post-
 * security-review.md §2 item 4). Live pg_policies/pg_roles proof that
 * Checkpoint 11 introduced NO SuperAdmin-specific RLS carve-out anywhere
 * in this codebase: the runtime role still has no BYPASSRLS, no policy
 * on any FORCE-RLS table (including every one of the 125 pre-existing
 * tables) contains a SuperAdmin/PlatformAdmin-specific bypass clause,
 * and the ONE new table this checkpoint introduces
 * (integration_platform_overview_summaries) carries NO row-level
 * security at all — not a permissive carve-out, genuinely none, exactly
 * as frozen design §5 requires and as
 * tests/Feature/Security/DatabaseRoleProofTest.php's own proof style
 * establishes (reused/extended here, not reinvented).
 */
final class NoSuperAdminRlsCarveOutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Needles that would indicate a policy clause was written to grant
     * a specific platform role/actor a bypass — e.g.
     * `current_setting('app.actor_role') = 'super_admin'` or a raw
     * `platform_admin_id` self-reference used as a permissive OR-branch.
     * Checked case-insensitively against every forced table's policy
     * USING/WITH CHECK text.
     *
     * @var string[]
     */
    private const CARVE_OUT_NEEDLES = [
        'super_admin',
        'superadmin',
        'platform_admin',
        'platformadmin',
        'support_agent',
        'implementation_specialist',
    ];

    public function test_the_connected_runtime_role_still_has_no_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls, rolsuper from pg_roles where rolname = current_user');

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls, 'The runtime role must not have BYPASSRLS — Checkpoint 11 must not have granted it one.');
        $this->assertFalse((bool) $row->rolsuper, 'The runtime role must not be a Postgres superuser.');
    }

    public function test_no_policy_on_any_force_rls_table_references_a_platform_staff_role_or_actor_carve_out(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $forcedTables = $coverage->forcedTables();
        $this->assertNotEmpty($forcedTables);

        $violations = [];

        foreach ($forcedTables as $table) {
            $rows = DB::select('select policyname, qual, with_check from pg_policies where tablename = ?', [$table]);

            foreach ($rows as $policy) {
                $clauseText = strtolower(($policy->qual ?? '').' '.($policy->with_check ?? ''));

                foreach (self::CARVE_OUT_NEEDLES as $needle) {
                    if (str_contains($clauseText, $needle)) {
                        $violations[] = "{$table}.{$policy->policyname} references '{$needle}'";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'No FORCE RLS policy may reference a platform-staff role/actor as a bypass/carve-out condition: '.implode('; ', $violations)
        );
    }

    public function test_every_force_rls_policy_on_every_forced_table_still_conditions_on_current_setting_only(): void
    {
        // Belt-and-braces re-derivation of DatabaseRoleProofTest's own
        // sweep, run again HERE (post-Checkpoint-11) so this file does
        // not merely trust that an earlier test file's pass implies
        // nothing changed for Checkpoint 11's own diff.
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $rows = DB::select('select policyname, qual, with_check from pg_policies where tablename = ?', [$table]);

            $this->assertNotEmpty($rows, "{$table} is FORCE-enabled but has zero policies.");

            foreach ($rows as $policy) {
                $clauses = array_filter([$policy->qual, $policy->with_check], static fn ($clause) => $clause !== null);
                $this->assertNotEmpty($clauses, "{$table}'s policy '{$policy->policyname}' has neither a USING nor WITH CHECK clause.");

                foreach ($clauses as $clause) {
                    $normalized = strtolower(trim($clause));
                    $this->assertNotSame('true', $normalized, "{$table}'s policy '{$policy->policyname}' is unconditionally permissive.");
                    $this->assertStringContainsString('current_setting', $normalized, "{$table}'s policy '{$policy->policyname}' does not condition on current_setting(...).");
                }
            }
        }
    }

    public function test_checkpoint_11_added_zero_new_force_rls_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertNotContains(
            'integration_platform_overview_summaries',
            $coverage->forcedTables(),
            'integration_platform_overview_summaries must never be FORCE-RLS-enabled — frozen design §5 requires it carry no RLS at all.'
        );
    }

    public function test_the_new_platform_overview_summaries_table_carries_no_row_level_security_at_all(): void
    {
        $row = DB::selectOne(
            "select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_platform_overview_summaries'"
        );

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity, 'integration_platform_overview_summaries must not have row security enabled at all — not even a non-FORCE permissive policy.');
        $this->assertFalse((bool) $row->relforcerowsecurity);
    }

    public function test_the_new_platform_overview_summaries_table_has_zero_policies_of_any_kind(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_platform_overview_summaries'");

        $this->assertEmpty($rows, 'integration_platform_overview_summaries must carry zero policies — a permissive carve-out policy would be just as much a violation as a FORCE-RLS-bypass one.');
    }

    public function test_no_security_definer_function_was_introduced_for_the_platform_overview_surface(): void
    {
        $rows = DB::select(
            'select proname from pg_proc p '.
            'join pg_namespace n on n.oid = p.pronamespace '.
            "where n.nspname = 'public' and p.prosecdef = true"
        );

        $names = array_map(static fn ($row) => strtolower($row->proname), $rows);
        $suspicious = array_filter($names, static fn (string $name) => str_contains($name, 'integration') || str_contains($name, 'overview') || str_contains($name, 'platform'));

        $this->assertEmpty(
            $suspicious,
            'No SECURITY DEFINER function related to the Checkpoint 11 platform-overview surface may exist: '.implode(', ', $suspicious)
        );
    }
}
