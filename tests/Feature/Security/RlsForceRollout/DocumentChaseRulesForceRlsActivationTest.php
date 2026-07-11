<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DocumentChaseRuleStatus;
use App\Models\DocumentChaseRule;
use App\Models\Firm;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DocumentChaseRulesForceRlsActivationTest — Section 39A-3K (batch 2 of
 * 5). Proves the fifteenth staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php)
 * is permanently active for document_chase_rules and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that every table forced by a prior section
 * (clients, firm_users, documents, deadlines, tasks, matters, invoices,
 * payments, conflict_check_runs, lead_sources, consultation_outcomes,
 * firm_leads, consultations) AND firm_practice_areas (this same batch)
 * remain forced simultaneously.
 *
 * No service change was made for this table — the one read call site
 * found (DocumentChaseSchedulerService::applicableRule()) was traced
 * and confirmed genuinely unreachable in production today (see the
 * migration's own docblock), so this file is standard FORCE/isolation
 * coverage only, matching the other tables in this batch.
 */
class DocumentChaseRulesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_thirteen_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $previouslyForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
            'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        ];

        foreach ($previouslyForced as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE RLS enabled after this batch.");
        }
    }

    public function test_firm_practice_areas_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_practice_areas'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_practice_areas must be FORCE RLS enabled alongside document_chase_rules in this batch.');
    }

    public function test_document_chase_rules_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'document_chase_rules'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_document_chase_rules_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'document_chase_rules'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'document_chase_rules must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_document_chase_rules(): void
    {
        $firm = Firm::factory()->create();
        DocumentChaseRule::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DocumentChaseRule::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_document_chase_rules(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('document_chase_rules')->insert([
            'firm_id' => $firm->id,
            'name' => 'No Context Rule',
            'status' => DocumentChaseRuleStatus::Active->value,
            'reminder_offsets_days' => json_encode([7, 3, 1]),
            'max_reminders' => 3,
            'channel' => 'email',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_document_chase_rules(): void
    {
        $firmA = Firm::factory()->create();
        $ruleA = $this->runWithFirmContext($firmA, fn () => DocumentChaseRule::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentChaseRule::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$ruleA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_document_chase_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => DocumentChaseRule::factory()->forFirm($firmA)->create());
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentChaseRule::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($ruleB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_document_chase_rule(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('document_chase_rules')->insertGetId([
                'firm_id' => $firmA->id,
                'name' => 'Valid Rule',
                'status' => DocumentChaseRuleStatus::Active->value,
                'reminder_offsets_days' => json_encode([7, 3, 1]),
                'max_reminders' => 3,
                'channel' => 'email',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_document_chase_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create(['name' => 'Original Name']));

        $this->runWithFirmContext($firmA, function () use ($ruleB) {
            DB::table('document_chase_rules')->where('id', $ruleB->id)->update(['name' => 'Hijacked Name']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentChaseRule::withoutGlobalScopes()->find($ruleB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->name);
    }

    public function test_firm_a_cannot_delete_firm_b_document_chase_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($ruleB) {
            DB::table('document_chase_rules')->where('id', $ruleB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentChaseRule::withoutGlobalScopes()->find($ruleB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B document_chase_rules.');
    }

    public function test_firm_a_cannot_insert_a_document_chase_rule_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('document_chase_rules')->insert([
                'firm_id' => $firmB->id,
                'name' => 'Cross-Firm Insert Attempt',
                'status' => DocumentChaseRuleStatus::Active->value,
                'reminder_offsets_days' => json_encode([7, 3, 1]),
                'max_reminders' => 3,
                'channel' => 'email',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleA = $this->runWithFirmContext($firmA, fn () => DocumentChaseRule::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($ruleA, $firmB) {
            DB::table('document_chase_rules')->where('id', $ruleA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => DocumentChaseRule::factory()->forFirm($firm)->create());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * The context-hold create() pattern: a bare
     * DocumentChaseRule::factory()->create() (no explicit firm) must
     * still succeed and be immediately readable — proving the factory
     * activates the matching PostgreSQL session context for its own
     * randomly-generated firm before inserting. escalate_to_user_id/
     * created_by reference the non-tenant users table, so there is no
     * nested tenant-owned parent to mismatch against here.
     */
    public function test_default_factory_creation_is_safe_and_immediately_readable(): void
    {
        $rule = DocumentChaseRule::factory()->create();

        $this->assertNotNull($rule->id);
        $this->assertNotNull($rule->firm_id);

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $rule->firm_id,
            fn () => DocumentChaseRule::withoutGlobalScopes()->find($rule->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'A bare DocumentChaseRule::factory()->create() must be readable under its own firm context.');
    }

    /**
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'document_chase_rules'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        foreach ($coverage->missingPreparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this batch must not add new policies for uncovered tables."
            );
        }
    }

    public function test_no_other_policy_was_changed(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'document_chase_rules'::regclass and polname = 'document_chase_rules_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original document_chase_rules_tenant_isolation policy must still exist.');
        $this->assertSame(
            "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)",
            $row->using_expr,
            'The existing policy USING expression must be unchanged by this batch.'
        );
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this batch.');
    }

    public function test_rls_prepared_not_enforced_remains_tracked(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_document_chase_scheduler_service_was_not_wired_with_tenant_context(): void
    {
        // Confirms the migration's own documented decision: this batch
        // deliberately did NOT add runWithFirmContext() wiring to
        // DocumentChaseSchedulerService, because its one read call site
        // (applicableRule()) is genuinely unreachable in production
        // today (no controller/Filament page/job/command reaches it).
        $changed = $this->changedOrUntrackedPaths('app/Services/DocumentChaseSchedulerService.php');

        $this->assertEmpty($changed, 'DocumentChaseSchedulerService.php must remain untouched by this batch — its read path is unreachable in production, so no context wiring was needed.');
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
