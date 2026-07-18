<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailMessageLink;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmailMessageLinksForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for email_message_links (database/migrations/
 * 2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/insert/update/delete,
 * correct same-firm access, and that every previously-forced table
 * remains forced simultaneously.
 *
 * This table's parent, email_messages, remains unprepared
 * (missingPreparedTables()) — several tests below explicitly assert
 * that this checkpoint leaves email_messages (and every other
 * still-uncovered table) untouched, proving the two tables' RLS states
 * are genuinely independent.
 *
 * This test deliberately does NOT assert that email_message_links
 * appears in RowLevelSecurityCoverageMappingService::preparedTables(),
 * and does NOT assert any exact "N prepared/missing tables" count —
 * the shared registry (app/Services/RowLevelSecurityCoverageMappingService.php)
 * is intentionally NOT touched by this commit; it is updated once by
 * the coordinator in a later wave-integration pass. This test instead
 * proves the live database state directly via pg_class/pg_policy,
 * which is unaffected by the registry not yet being updated.
 */
class EmailMessageLinksForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php';

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_email_message_links_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'email_message_links'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_email_message_links_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'email_message_links'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'email_message_links must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_email_messages_remains_unprepared(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'email_messages'");

        $this->assertNotNull($row);
        $this->assertFalse(
            (bool) $row->relrowsecurity,
            'email_messages (the parent table) must remain unprepared — this checkpoint only activates email_message_links, its own direct firm_id column making that independently safe.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'email_message_links'::regclass and polname = 'email_message_links_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The email_message_links_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_email_message_links(): void
    {
        $firm = Firm::factory()->create();
        $this->createLinkForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, EmailMessageLink::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_email_message_links(): void
    {
        $firm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('email_message_links')->insert([
            'firm_id' => $firm->id,
            'email_message_id' => $message->id,
            'matter_id' => null,
            'client_id' => null,
            'linked_by_firm_user_id' => $actor->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deliberately does NOT rely on any factory context-hold override —
     * EmailMessageLinkFactory was intentionally NOT modified to
     * auto-establish tenant context by this checkpoint (its bare
     * default path was already tenant-consistent, so no fix was
     * needed), so a bare factory create() must fail closed exactly
     * like the raw insert above.
     *
     * Test-runner correction (execution against a real database
     * revealed this): a truly argument-less
     * EmailMessageLink::factory()->create() call lazily resolves its
     * own default matter_id/linked_by_firm_user_id via
     * Matter::factory()->create()/FirmUser::factory()->create() —
     * both of which are themselves already FORCE ROW LEVEL SECURITY
     * tables whose own factories (Section 39A-3A/39A-3B) deliberately
     * SET AND LEAVE a matching database tenant context active as an
     * unrelated, documented "create then read" convenience (see
     * MatterFactory::create()/FirmUserFactory::create()). Left
     * unaccounted for, that leaked context would silently make the
     * final email_message_links insert succeed despite "no context,"
     * masking the exact behavior this test exists to prove. To isolate
     * email_message_links' own fail-closed behavior, its matter/actor
     * dependencies are built up front by id (their own factories'
     * leaked context is irrelevant here), then tenant context is
     * cleared immediately before the create() call under test, and all
     * relevant keys are passed explicitly so none of
     * EmailMessageLinkFactory's own default-resolving closures fire.
     */
    public function test_bare_factory_create_without_context_fails_closed(): void
    {
        $firm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        EmailMessageLink::factory()->create([
            'firm_id' => $firm->id,
            'email_message_id' => $message->id,
            'matter_id' => $matter->id,
            'client_id' => null,
            'linked_by_firm_user_id' => $actor->id,
        ]);
    }

    public function test_firm_a_context_can_read_its_own_email_message_links(): void
    {
        $firmA = Firm::factory()->create();
        $linkA = $this->createLinkForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailMessageLink::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$linkA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_email_message_links(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createLinkForFirm($firmA);
        $linkB = $this->createLinkForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailMessageLink::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($linkB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_email_message_link_row(): void
    {
        $firmA = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firmA)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $actor = FirmUser::factory()->forFirm($firmA)->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $message, $actor) {
            return DB::table('email_message_links')->insertGetId([
                'firm_id' => $firmA->id,
                'email_message_id' => $message->id,
                'matter_id' => null,
                'client_id' => null,
                'linked_by_firm_user_id' => $actor->id,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_email_message_links(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $linkB = $this->createLinkForFirm($firmB, ['is_primary' => true]);

        $this->runWithFirmContext($firmA, function () use ($linkB) {
            DB::table('email_message_links')->where('id', $linkB->id)->update(['is_primary' => false]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailMessageLink::withoutGlobalScopes()->find($linkB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertTrue((bool) $reReadAsFirmB->is_primary);
    }

    public function test_firm_a_cannot_delete_firm_b_email_message_links(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $linkB = $this->createLinkForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($linkB) {
            DB::table('email_message_links')->where('id', $linkB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailMessageLink::withoutGlobalScopes()->find($linkB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B email_message_links.');
    }

    public function test_firm_a_cannot_insert_an_email_message_link_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = EmailAccount::factory()->forFirm($firmB)->create();
        $messageB = EmailMessage::factory()->forAccount($accountB)->create();
        $actorB = FirmUser::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $messageB, $actorB) {
            DB::table('email_message_links')->insert([
                'firm_id' => $firmB->id,
                'email_message_id' => $messageB->id,
                'matter_id' => null,
                'client_id' => null,
                'linked_by_firm_user_id' => $actorB->id,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $linkA = $this->createLinkForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($linkA, $firmB) {
            DB::table('email_message_links')->where('id', $linkA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createLinkForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'email_message_links'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'email_message_links'::regclass and polname = 'email_message_links_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only email_message_links — every
     * other table's relrowsecurity/relforcerowsecurity state (sampled:
     * every PREPARED table, plus email_messages and a representative
     * still-uncovered table) is bit-for-bit identical before and after
     * a down()+up() round trip.
     */
    public function test_migration_round_trip_affects_only_email_message_links(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'email_messages';
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
                "{$table}'s relrowsecurity must be unaffected by the email_message_links migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the email_message_links migration round trip."
            );
        }
    }

    /**
     * Every other still-uncovered tenant table (i.e. every entry of
     * missingPreparedTables() other than email_message_links itself,
     * which this checkpoint activates ahead of the shared registry
     * being updated — see this class's own docblock) must remain
     * untouched.
     */
    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if ($table === 'email_message_links') {
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
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once this table has landed.'
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

    public function test_only_this_checkpoints_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowed = [
            self::MIGRATION_PATH,
            'app/Services/EmailMessageLinkingService.php',
            'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
            'tests/Feature/Email/MessageLinks/EmailMessageLinkingServiceTest.php',
        ];

        $unexpected = array_values(array_diff($changed, $allowed));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this checkpoint: '.implode(', ', $unexpected));
    }

    /**
     * Creates a fully firm-consistent email_message_links row (message,
     * matter, and linking actor all tied to the same firm), created
     * under an explicit runWithFirmContext() wrap since
     * EmailMessageLinkFactory itself was NOT modified to
     * auto-establish tenant context (matching the firm_ai_settings
     * checkpoint's own deliberate choice — see
     * test_bare_factory_create_without_context_fails_closed).
     *
     * Test-runner correction (execution against a real database
     * revealed this): Matter::factory()->create()/FirmUser::factory()
     * ->create() (Section 39A-3A/39A-3B) deliberately set AND LEAVE
     * the database tenant context active after creating their own
     * row, as their own documented "create then read" convenience —
     * cleared explicitly below so the runWithFirmContext() wrap that
     * follows snapshots a genuinely clean "no context" baseline to
     * restore to afterward, rather than inheriting that unrelated
     * leaked context as its own "previous" state.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createLinkForFirm(Firm $firm, array $overrides = []): EmailMessageLink
    {
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => EmailMessageLink::factory()->create(array_merge([
            'firm_id' => $firm->id,
            'email_message_id' => $message->id,
            'matter_id' => $matter->id,
            'client_id' => null,
            'linked_by_firm_user_id' => $actor->id,
            'is_primary' => true,
        ], $overrides)));
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
