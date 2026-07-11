<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\EmployeeRate;
use App\Models\Firm;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\EmployeeRateService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmployeeRatesForceRlsActivationTest — Section 39A-3K (batch 3 of 5).
 * Proves the sixteenth staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php)
 * is permanently active for employee_rates and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that every table forced by a prior section or
 * by this same batch (clients, firm_users, documents, deadlines, tasks,
 * matters, invoices, payments, conflict_check_runs, lead_sources,
 * consultation_outcomes, firm_leads, consultations, firm_practice_areas,
 * document_chase_rules) remains forced simultaneously.
 *
 * EmployeeRateService::setRate()/currentRateFor() now self-wrap in
 * runWithFirmContext() (removing the previous bare DB::transaction()) —
 * this file proves that self-wrap actually works end to end (not just
 * that the source no longer references DB::transaction()), that
 * nesting/leakage across back-to-back calls is safe, and that the
 * known, documented, OUT-OF-SCOPE firm_users-membership gap is
 * deliberately NOT newly asserted as a failure here (see
 * EmployeeRateService's own docblock).
 */
class EmployeeRatesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmployeeRateService();
    }

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

    public function test_firm_practice_areas_and_document_chase_rules_are_also_force_row_level_security_enabled(): void
    {
        foreach (['firm_practice_areas', 'document_chase_rules'] as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row);
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must be FORCE RLS enabled alongside employee_rates in this batch.");
        }
    }

    public function test_employee_rates_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'employee_rates'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_employee_rates_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'employee_rates'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'employee_rates must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_employee_rates(): void
    {
        $firm = Firm::factory()->create();
        EmployeeRate::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, EmployeeRate::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_employee_rates(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('employee_rates')->insert([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'billing_rate_cents' => 25000,
            'cost_rate_cents' => 12000,
            'currency' => 'usd',
            'effective_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_employee_rates(): void
    {
        $firmA = Firm::factory()->create();
        $rateA = $this->runWithFirmContext($firmA, fn () => EmployeeRate::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmployeeRate::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$rateA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_employee_rates(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => EmployeeRate::factory()->forFirm($firmA)->create());
        $rateB = $this->runWithFirmContext($firmB, fn () => EmployeeRate::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmployeeRate::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($rateB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_employee_rate(): void
    {
        $firmA = Firm::factory()->create();
        $user = User::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $user) {
            return DB::table('employee_rates')->insertGetId([
                'firm_id' => $firmA->id,
                'user_id' => $user->id,
                'billing_rate_cents' => 25000,
                'cost_rate_cents' => 12000,
                'currency' => 'usd',
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_employee_rates(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rateB = $this->runWithFirmContext($firmB, fn () => EmployeeRate::factory()->forFirm($firmB)->create(['billing_rate_cents' => 20000]));

        $this->runWithFirmContext($firmA, function () use ($rateB) {
            DB::table('employee_rates')->where('id', $rateB->id)->update(['billing_rate_cents' => 99999]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmployeeRate::withoutGlobalScopes()->find($rateB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(20000, $reReadAsFirmB->billing_rate_cents);
    }

    public function test_firm_a_cannot_delete_firm_b_employee_rates(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rateB = $this->runWithFirmContext($firmB, fn () => EmployeeRate::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($rateB) {
            DB::table('employee_rates')->where('id', $rateB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmployeeRate::withoutGlobalScopes()->find($rateB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B employee_rates.');
    }

    public function test_firm_a_cannot_insert_an_employee_rate_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = User::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $user) {
            DB::table('employee_rates')->insert([
                'firm_id' => $firmB->id,
                'user_id' => $user->id,
                'billing_rate_cents' => 25000,
                'cost_rate_cents' => 12000,
                'currency' => 'usd',
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rateA = $this->runWithFirmContext($firmA, fn () => EmployeeRate::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($rateA, $firmB) {
            DB::table('employee_rates')->where('id', $rateA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => EmployeeRate::factory()->forFirm($firm)->create());

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
     * EmployeeRate::factory()->create() (no explicit firm) must still
     * succeed and be immediately readable — proving the factory
     * activates the matching PostgreSQL session context for its own
     * randomly-generated firm before inserting. user_id references the
     * non-tenant users table, so there is no nested tenant-owned parent
     * to mismatch against here.
     */
    public function test_default_factory_creation_is_safe_and_immediately_readable(): void
    {
        $rate = EmployeeRate::factory()->create();

        $this->assertNotNull($rate->id);
        $this->assertNotNull($rate->firm_id);

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $rate->firm_id,
            fn () => EmployeeRate::withoutGlobalScopes()->find($rate->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'A bare EmployeeRate::factory()->create() must be readable under its own firm context.');
    }

    /**
     * Core proof: EmployeeRateService::setRate() (self-wrapped in
     * runWithFirmContext() by this batch, replacing the previous bare
     * DB::transaction()) still functions for its documented legitimate
     * use case — creating an open-ended rate, then closing it out and
     * opening a new one on a subsequent call — under FORCE. Re-reads
     * are performed under an explicit, scoped context since setRate()
     * always clears its own context before returning.
     */
    public function test_set_rate_service_still_functions_under_force(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $first = $this->service->setRate($firm, $user, 20000, 10000, effectiveFrom: now()->subMonths(2));
        $second = $this->service->setRate($firm, $user, 25000, 12000, effectiveFrom: now());

        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);

        [$firstFresh, $secondFresh, $openCount] = $this->runWithFirmContext($firm, fn () => [
            EmployeeRate::withoutGlobalScopes()->find($first->id),
            EmployeeRate::withoutGlobalScopes()->find($second->id),
            EmployeeRate::withoutGlobalScopes()->where('firm_id', $firm->id)->where('user_id', $user->id)->whereNull('effective_to')->count(),
        ]);

        $this->assertNotNull($firstFresh->effective_to, 'setRate() must close out the previous open-ended rate.');
        $this->assertNull($secondFresh->effective_to, 'setRate() must leave the newest rate open-ended.');
        $this->assertSame(1, $openCount, 'Exactly one open-ended rate must exist per firm/user pair.');
    }

    /**
     * currentRateFor() (also self-wrapped in runWithFirmContext() by
     * this batch) must still resolve the correct historical rate under
     * FORCE.
     */
    public function test_current_rate_for_still_functions_under_force(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $old = $this->service->setRate($firm, $user, 20000, 10000, effectiveFrom: now()->subMonths(2));
        $new = $this->service->setRate($firm, $user, 25000, 12000, effectiveFrom: now()->subDays(1));

        $asOfOld = $this->service->currentRateFor($firm, $user, now()->subMonths(1));
        $asOfNow = $this->service->currentRateFor($firm, $user);

        $this->assertSame($old->id, $asOfOld->id);
        $this->assertSame($new->id, $asOfNow->id);
    }

    /**
     * Proves setRate() correctly self-clears its own context between
     * two independent, back-to-back calls — no leaked context from the
     * first call causes the second (for a DIFFERENT firm) to see or
     * affect the wrong firm's data, and no nesting/savepoint regression
     * exists from removing the old bare DB::transaction().
     */
    public function test_back_to_back_set_rate_calls_for_different_firms_do_not_leak_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $rateA = $this->service->setRate($firmA, $userA, 20000, 10000);
        $rateB = $this->service->setRate($firmB, $userB, 30000, 15000);

        $this->assertNoDatabaseTenantContext();

        $visibleFromA = $this->runWithFirmContext($firmA, fn () => EmployeeRate::withoutGlobalScopes()->pluck('id')->all());
        $visibleFromB = $this->runWithFirmContext($firmB, fn () => EmployeeRate::withoutGlobalScopes()->pluck('id')->all());

        $this->assertSame([$rateA->id], $visibleFromA);
        $this->assertSame([$rateB->id], $visibleFromB);
    }

    /**
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'employee_rates'");

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
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'employee_rates'::regclass and polname = 'employee_rates_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original employee_rates_tenant_isolation policy must still exist.');
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

    /**
     * Documented, out-of-scope gap: no firm_users-membership check
     * exists for setRate()'s $employee — this batch's FORCE activation
     * does not change that, and this test explicitly does NOT assert
     * that a non-member user's rate write "should fail" (see
     * EmployeeRateService's own docblock for why that would be a
     * business-authorization change, not tenant-isolation wiring).
     * Proves only what IS true today: a rate can be set for a user with
     * no firm_users tie at all, and it is still correctly isolated by
     * its own firm_id.
     */
    public function test_set_rate_for_a_user_with_no_firm_membership_still_succeeds_and_is_still_firm_isolated(): void
    {
        $firm = Firm::factory()->create();
        $unaffiliatedUser = User::factory()->create();

        $rate = $this->service->setRate($firm, $unaffiliatedUser, 18000, 9000);

        $this->assertNotNull($rate->id);

        $visibleFromFirm = $this->runWithFirmContext($firm, fn () => EmployeeRate::withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame([$rate->id], $visibleFromFirm);
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
