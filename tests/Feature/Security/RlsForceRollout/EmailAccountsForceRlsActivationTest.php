<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\EmailAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmailAccountsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for email_accounts (database/migrations/
 * 2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/insert/update/delete,
 * correct same-firm access, and that every previously-forced table
 * remains forced simultaneously.
 *
 * First of a four-table, one-batch Section 39A-5 Wave 5 activation:
 * email_accounts (this file), email_messages, email_attachments,
 * email_sync_events. All four migrations, their five updated writer
 * services, and their four updated factories landed together as ONE
 * combined batch — see the migration's own docblock for the full
 * batch rationale.
 *
 * This test deliberately does NOT assert that email_accounts appears
 * in RowLevelSecurityCoverageMappingService::preparedTables(), and
 * does NOT assert any exact "N prepared/missing tables" count — the
 * shared registry (app/Services/RowLevelSecurityCoverageMappingService.php)
 * is intentionally NOT touched by this commit; it is updated once by
 * the coordinator in a later wave-integration pass. This test instead
 * proves the live database state directly via pg_class/pg_policy,
 * which is unaffected by the registry not yet being updated.
 */
class EmailAccountsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php';

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_email_accounts_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'email_accounts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_email_accounts_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'email_accounts'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'email_accounts must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'email_accounts'::regclass and polname = 'email_accounts_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The email_accounts_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_email_accounts(): void
    {
        $firm = Firm::factory()->create();
        $this->createAccountForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, EmailAccount::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_email_accounts(): void
    {
        $firm = Firm::factory()->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('email_accounts')->insert($this->rowAttributes($firm, $actor));
    }

    /**
     * Unlike EmailMessageLinkFactory (deliberately NOT modified),
     * EmailAccountFactory DID gain a context-hold create() override in
     * this batch (see database/factories/EmailAccountFactory.php's own
     * docblock) — its bare default-creation path is already
     * tenant-consistent (connected_by_firm_user_id is a lazy closure
     * reading the already-resolved firm_id, so the two columns can
     * never disagree), so a bare EmailAccount::factory()->create() must
     * now SUCCEED even with no ambient context, proving the actual
     * current behavior rather than assuming the pre-Wave-5 fail-closed
     * shape still applies.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $account = EmailAccount::factory()->create();

        $this->assertNotNull($account->id);
        $this->assertNotNull($account->firm_id);

        $persisted = $this->runWithFirmContext(
            $account->firm_id,
            fn () => EmailAccount::withoutGlobalScopes()->with('connectedByFirmUser')->find($account->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $account->firm_id,
            $persisted->connectedByFirmUser->firm_id,
            'Bare factory default must not produce a cross-firm connected_by_firm_user_id mismatch.'
        );
    }

    public function test_firm_a_context_can_read_its_own_email_accounts(): void
    {
        $firmA = Firm::factory()->create();
        $accountA = $this->createAccountForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailAccount::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$accountA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_email_accounts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createAccountForFirm($firmA);
        $accountB = $this->createAccountForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailAccount::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($accountB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_email_account_row(): void
    {
        $firmA = Firm::factory()->create();
        $actor = FirmUser::factory()->forFirm($firmA)->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('email_accounts')->insertGetId($this->rowAttributes($firmA, $actor)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_email_accounts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->createAccountForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($accountB) {
            DB::table('email_accounts')->where('id', $accountB->id)->update(['error_reason' => 'attempted cross-firm edit']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailAccount::withoutGlobalScopes()->find($accountB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNull($reReadAsFirmB->error_reason, 'Firm A context must not be able to update Firm B\'s email_accounts row.');
    }

    public function test_firm_a_cannot_delete_firm_b_email_accounts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->createAccountForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($accountB) {
            DB::table('email_accounts')->where('id', $accountB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailAccount::withoutGlobalScopes()->find($accountB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B email_accounts.');
    }

    public function test_firm_a_cannot_insert_an_email_account_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actorB = FirmUser::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $actorB) {
            DB::table('email_accounts')->insert($this->rowAttributes($firmB, $actorB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountA = $this->createAccountForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($accountA, $firmB) {
            DB::table('email_accounts')->where('id', $accountA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * email_accounts is the root of this batch's FK chain, so it has
     * no in-scope PARENT to reference — but connected_by_firm_user_id
     * IS a related-model FK (into firm_users, out of this batch's
     * scope but already FORCE'd). RLS on email_accounts only checks
     * this row's own firm_id — it does not (and cannot) verify that
     * connected_by_firm_user_id actually points at a firm_users row
     * belonging to the SAME firm. Proven directly, not assumed: a raw
     * insert can and does create this mismatch.
     */
    public function test_email_account_row_can_reference_a_different_firms_connected_by_firm_user_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actorB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $actorB) {
            return DB::table('email_accounts')->insertGetId($this->rowAttributes($firmA, $actorB));
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => EmailAccount::withoutGlobalScopes()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($actorB->id, $persisted->connected_by_firm_user_id, 'The row genuinely persisted pointing at firm B\'s own firm_users row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createAccountForFirm($firm);

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
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration
     * introduced the policy itself. up() is restored in a finally
     * block so later tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'email_accounts'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'email_accounts'::regclass and polname = 'email_accounts_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only email_accounts — every other
     * table's relrowsecurity/relforcerowsecurity state (sampled: every
     * PREPARED table, plus its sibling batch tables and a
     * representative still-uncovered table) is bit-for-bit identical
     * before and after a down()+up() round trip.
     */
    public function test_migration_round_trip_affects_only_email_accounts(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'email_messages';
        $otherTables[] = 'email_attachments';
        $otherTables[] = 'email_sync_events';
        $otherTables[] = 'accounting_export_batches'; // a representative still-uncovered table

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the email_accounts migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the email_accounts migration round trip."
            );
        }
    }

    /**
     * Every other still-uncovered tenant table (i.e. every entry of
     * missingPreparedTables() other than this batch's own four tables,
     * which this checkpoint activates ahead of the shared registry
     * being updated — see this class's own docblock) must remain
     * untouched.
     */
    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $thisBatch = ['email_accounts', 'email_messages', 'email_attachments', 'email_sync_events'];

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, $thisBatch, true)) {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this checkpoint must not add policies for any other uncovered table."
            );
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php');

        $this->assertEmpty(
            $changed,
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once this batch has landed.'
        );
    }

    public function test_gap_registry_doc_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('docs/governance/rls-gap-registry.md');

        $this->assertEmpty($changed, 'docs/governance/rls-gap-registry.md must remain untouched by this checkpoint — reserved for a later wave-integration commit.');
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    /**
     * This batch's expected file set — all four tables' migrations,
     * the five updated writer services, the four updated factories,
     * and this batch's four focused test files plus its append-only
     * companion and its one regression-fixed test file land together
     * as ONE combined batch (see the migration's own docblock) — every
     * sibling checkpoint test in this batch (Email{Messages,
     * Attachments,SyncEvents}ForceRlsActivationTest) declares the
     * identical allowed set.
     */
    public function test_only_this_batchs_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $unexpected = array_values(array_diff($changed, $this->allowedFiles()));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this batch: '.implode(', ', $unexpected));
    }

    /**
     * @return array<int, string>
     */
    private function allowedFiles(): array
    {
        return [
            'database/migrations/2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php',
            'database/migrations/2026_08_27_950026_prepare_row_level_security_and_force_rls_on_email_messages_table.php',
            'database/migrations/2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php',
            'database/migrations/2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php',
            'app/Models/EmailSyncEvent.php',
            'app/Services/EmailAccountService.php',
            'app/Services/EmailAttachmentPromotionService.php',
            'app/Services/EmailOAuthTokenService.php',
            'app/Services/EmailSyncService.php',
            'database/factories/EmailAccountFactory.php',
            'database/factories/EmailAttachmentFactory.php',
            'database/factories/EmailMessageFactory.php',
            'database/factories/EmailSyncEventFactory.php',
            'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
            'tests/Feature/Email/Sync/EmailSyncEventAppendOnlyTest.php',
            'tests/Feature/TenantIsolation/EmailTenantIsolationTest.php',
            // This firewall test gained a narrow, additive-only
            // "wave 5 migration files exist" check, mirroring every
            // prior wave's own identical addition — see the file's own
            // updated docblock for the exact section this batch added.
            'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccountForFirm(Firm $firm, array $overrides = []): EmailAccount
    {
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => EmailAccount::factory()->create(array_merge([
            'firm_id' => $firm->id,
            'connected_by_firm_user_id' => $actor->id,
        ], $overrides)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, FirmUser $actor): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'provider' => 'gmail',
            'mailbox_address' => 'mailbox-'.uniqid().'@example.com',
            'connection_status' => 'connected',
            'storage_mode' => 'disabled',
            'connected_by_firm_user_id' => $actor->id,
            'last_synced_at' => null,
            'error_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
