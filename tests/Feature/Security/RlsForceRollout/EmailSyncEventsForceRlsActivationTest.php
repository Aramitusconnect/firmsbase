<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\EmailAccount;
use App\Models\EmailSyncEvent;
use App\Models\Firm;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmailSyncEventsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for email_sync_events (database/migrations/
 * 2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php)
 * is permanently active and behaves correctly.
 *
 * Fourth and last of the four-table, one-batch Section 39A-5 Wave 5
 * activation — see EmailAccountsForceRlsActivationTest's own docblock
 * for the full combined-batch rationale; this file declares the
 * identical allowedFiles() set.
 *
 * Unlike email_accounts/email_messages/email_attachments,
 * EmailSyncEvent deliberately does NOT use BelongsToTenant (Phase 8
 * ImportAuditEvent precedent) — so unlike its three sibling tables in
 * this batch, this table has NO application-layer global-scope
 * backstop at all: once FORCE is active here, tenant isolation
 * depends entirely on this policy. Every read below therefore uses a
 * plain query() (no withoutGlobalScopes() needed — there is no global
 * scope registered to strip).
 *
 * Append-only enforcement (a separate mechanism from RLS, proven in
 * this file's own companion test, tests/Feature/Email/Sync/
 * EmailSyncEventAppendOnlyTest.php) is NOT re-proven here beyond what
 * this file's own cross-firm update/delete tests need — those use a
 * raw DB::table() bypass of Eloquent specifically so the RLS proof is
 * isolated from the model's booted() guard, which would otherwise
 * throw first regardless of which firm's context is active.
 *
 * This test deliberately does NOT assert that email_sync_events
 * appears in RowLevelSecurityCoverageMappingService::preparedTables(),
 * and does NOT assert any exact "N prepared/missing tables" count —
 * the shared registry is intentionally NOT touched by this commit; it
 * is updated once by the coordinator in a later wave-integration pass.
 */
class EmailSyncEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php';

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_email_sync_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'email_sync_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_email_sync_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'email_sync_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'email_sync_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'email_sync_events'::regclass and polname = 'email_sync_events_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The email_sync_events_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_email_sync_events(): void
    {
        $firm = Firm::factory()->create();
        $this->createEventForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, EmailSyncEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_write_email_sync_events(): void
    {
        $firm = Firm::factory()->create();
        $account = $this->runWithFirmContext($firm, fn () => EmailAccount::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('email_sync_events')->insert($this->rowAttributes($firm, $account));
    }

    /**
     * EmailSyncEventFactory DID gain a context-hold create() override
     * in this batch — its bare default-creation path is already
     * tenant-consistent (firm_id is a lazy closure reading the created
     * EmailAccount's own firm_id), so a bare EmailSyncEvent::factory()
     * ->create() must now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $event = EmailSyncEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);

        $persisted = $this->runWithFirmContext(
            $event->firm_id,
            fn () => EmailSyncEvent::query()->with('emailAccount')->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $event->firm_id,
            $persisted->emailAccount->firm_id,
            'Bare factory default must not produce a cross-firm email_account_id mismatch.'
        );
    }

    public function test_firm_a_context_can_read_its_own_email_sync_events(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailSyncEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_email_sync_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createEventForFirm($firmA);
        $eventB = $this->createEventForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailSyncEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_email_sync_event_row(): void
    {
        $firmA = Firm::factory()->create();
        $accountA = $this->runWithFirmContext($firmA, fn () => EmailAccount::factory()->forFirm($firmA)->create());

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('email_sync_events')->insertGetId($this->rowAttributes($firmA, $accountA)),
        );

        $this->assertIsInt($insertedId);
    }

    /**
     * Raw query-builder update (bypassing Eloquent, whose own
     * booted() guard would throw first regardless — see the
     * companion EmailSyncEventAppendOnlyTest for that proof) isolates
     * this test to what RLS alone denies for a cross-firm update.
     */
    public function test_firm_a_cannot_update_firm_b_email_sync_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($eventB) {
            return DB::table('email_sync_events')->where('id', $eventB->id)->update(['detail' => 'attempted cross-firm edit']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s email_sync_events row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailSyncEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNull($reReadAsFirmB->detail);
    }

    public function test_firm_a_cannot_delete_firm_b_email_sync_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('email_sync_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailSyncEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B email_sync_events.');
    }

    public function test_firm_a_cannot_insert_an_email_sync_event_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->runWithFirmContext($firmB, fn () => EmailAccount::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $accountB) {
            DB::table('email_sync_events')->insert($this->rowAttributes($firmB, $accountB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($eventA, $firmB) {
            DB::table('email_sync_events')->where('id', $eventA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Per the migration's own docblock, gap #1: no composite FK/CHECK/
     * trigger ties email_sync_events.firm_id to the ACTUAL firm_id of
     * the email_accounts row email_account_id (nullable) points at,
     * when present. RLS only checks this row's own firm_id. Proven
     * directly: a raw insert can and does create this mismatch.
     */
    public function test_email_sync_event_row_can_reference_a_different_firms_email_account_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->runWithFirmContext($firmB, fn () => EmailAccount::factory()->forFirm($firmB)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $accountB) {
            return DB::table('email_sync_events')->insertGetId($this->rowAttributes($firmA, $accountB));
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => EmailSyncEvent::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($accountB->id, $persisted->email_account_id, 'The row genuinely persisted pointing at firm B\'s own email_accounts row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createEventForFirm($firm);

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

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'email_sync_events'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'email_sync_events'::regclass and polname = 'email_sync_events_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_email_sync_events(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'email_accounts';
        $otherTables[] = 'email_messages';
        $otherTables[] = 'email_attachments';
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
                "{$table}'s relrowsecurity must be unaffected by the email_sync_events migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the email_sync_events migration round trip."
            );
        }
    }

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

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

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
            'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEventForFirm(Firm $firm, array $overrides = []): EmailSyncEvent
    {
        $account = $this->runWithFirmContext($firm, fn () => EmailAccount::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => EmailSyncEvent::factory()->create(array_merge([
            'firm_id' => $firm->id,
            'email_account_id' => $account->id,
        ], $overrides)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, EmailAccount $account): array
    {
        return [
            'firm_id' => $firm->id,
            'email_account_id' => $account->id,
            'event_type' => 'sync_run',
            'outcome' => 'success',
            'resulting_cursor' => '1',
            'detail' => null,
            'created_at' => now(),
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
