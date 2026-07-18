<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Firm;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmailAttachmentsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for email_attachments (database/migrations/
 * 2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php)
 * is permanently active and behaves correctly.
 *
 * Third of the four-table, one-batch Section 39A-5 Wave 5 activation
 * — see EmailAccountsForceRlsActivationTest's own docblock for the
 * full combined-batch rationale; this file declares the identical
 * allowedFiles() set.
 *
 * This test deliberately does NOT assert that email_attachments
 * appears in RowLevelSecurityCoverageMappingService::preparedTables(),
 * and does NOT assert any exact "N prepared/missing tables" count —
 * the shared registry is intentionally NOT touched by this commit; it
 * is updated once by the coordinator in a later wave-integration pass.
 */
class EmailAttachmentsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php';

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_email_attachments_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'email_attachments'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_email_attachments_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'email_attachments'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'email_attachments must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'email_attachments'::regclass and polname = 'email_attachments_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The email_attachments_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_email_attachments(): void
    {
        $firm = Firm::factory()->create();
        $this->createAttachmentForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, EmailAttachment::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_email_attachments(): void
    {
        $firm = Firm::factory()->create();
        $message = $this->runWithFirmContext($firm, function () use ($firm) {
            $account = EmailAccount::factory()->forFirm($firm)->create();

            return EmailMessage::factory()->forAccount($account)->create();
        });

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('email_attachments')->insert($this->rowAttributes($firm, $message));
    }

    /**
     * EmailAttachmentFactory DID gain a context-hold create() override
     * in this batch — its bare default-creation path is already
     * tenant-consistent (firm_id is a lazy closure reading the created
     * EmailMessage's own firm_id), so a bare EmailAttachment::factory()
     * ->create() must now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $attachment = EmailAttachment::factory()->create();

        $this->assertNotNull($attachment->id);
        $this->assertNotNull($attachment->firm_id);

        $persisted = $this->runWithFirmContext(
            $attachment->firm_id,
            fn () => EmailAttachment::withoutGlobalScopes()->with('emailMessage')->find($attachment->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $attachment->firm_id,
            $persisted->emailMessage->firm_id,
            'Bare factory default must not produce a cross-firm email_message_id mismatch.'
        );
    }

    public function test_firm_a_context_can_read_its_own_email_attachments(): void
    {
        $firmA = Firm::factory()->create();
        $attachmentA = $this->createAttachmentForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailAttachment::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$attachmentA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_email_attachments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createAttachmentForFirm($firmA);
        $attachmentB = $this->createAttachmentForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailAttachment::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($attachmentB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_email_attachment_row(): void
    {
        $firmA = Firm::factory()->create();
        $messageA = $this->runWithFirmContext($firmA, function () use ($firmA) {
            $account = EmailAccount::factory()->forFirm($firmA)->create();

            return EmailMessage::factory()->forAccount($account)->create();
        });

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('email_attachments')->insertGetId($this->rowAttributes($firmA, $messageA)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_email_attachments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $attachmentB = $this->createAttachmentForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($attachmentB) {
            DB::table('email_attachments')->where('id', $attachmentB->id)->update(['blocked_reason' => 'attempted cross-firm edit']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailAttachment::withoutGlobalScopes()->find($attachmentB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNull($reReadAsFirmB->blocked_reason);
    }

    public function test_firm_a_cannot_delete_firm_b_email_attachments(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $attachmentB = $this->createAttachmentForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($attachmentB) {
            DB::table('email_attachments')->where('id', $attachmentB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailAttachment::withoutGlobalScopes()->find($attachmentB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B email_attachments.');
    }

    public function test_firm_a_cannot_insert_an_email_attachment_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $messageB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $account = EmailAccount::factory()->forFirm($firmB)->create();

            return EmailMessage::factory()->forAccount($account)->create();
        });

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $messageB) {
            DB::table('email_attachments')->insert($this->rowAttributes($firmB, $messageB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $attachmentA = $this->createAttachmentForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($attachmentA, $firmB) {
            DB::table('email_attachments')->where('id', $attachmentA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Per the migration's own docblock, gap #1: no composite FK/CHECK/
     * trigger ties email_attachments.firm_id to the ACTUAL firm_id of
     * the email_messages row email_message_id points at. RLS only
     * checks this row's own firm_id. Proven directly: a raw insert can
     * and does create this mismatch.
     */
    public function test_email_attachment_row_can_reference_a_different_firms_email_message_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $messageB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $account = EmailAccount::factory()->forFirm($firmB)->create();

            return EmailMessage::factory()->forAccount($account)->create();
        });

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $messageB) {
            return DB::table('email_attachments')->insertGetId($this->rowAttributes($firmA, $messageB));
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => EmailAttachment::withoutGlobalScopes()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($messageB->id, $persisted->email_message_id, 'The row genuinely persisted pointing at firm B\'s own email_messages row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createAttachmentForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'email_attachments'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'email_attachments'::regclass and polname = 'email_attachments_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_email_attachments(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'email_accounts';
        $otherTables[] = 'email_messages';
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
                "{$table}'s relrowsecurity must be unaffected by the email_attachments migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the email_attachments migration round trip."
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
    private function createAttachmentForFirm(Firm $firm, array $overrides = []): EmailAttachment
    {
        $message = $this->runWithFirmContext($firm, function () use ($firm) {
            $account = EmailAccount::factory()->forFirm($firm)->create();

            return EmailMessage::factory()->forAccount($account)->create();
        });

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => EmailAttachment::factory()->create(array_merge([
            'firm_id' => $firm->id,
            'email_message_id' => $message->id,
        ], $overrides)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, EmailMessage $message): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'email_message_id' => $message->id,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'provider_attachment_id' => 'att-'.uniqid(),
            'scan_status' => 'pending',
            'simulated_storage_path' => "email-attachments/fixture/{$firm->id}/".uniqid(),
            'document_id' => null,
            'promotion_status' => 'pending',
            'blocked_reason' => null,
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
