<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TemplateUpgradeLogStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePack;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradeLog;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TemplatePackInstallationService;
use App\Services\TemplateUpgradeLogService;
use App\Services\TemplateUpgradePreviewService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TemplateUpgradeLogsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 7, Table Phase C. Proves the twenty-fifth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930007_force_rls_on_template_upgrade_logs_table.php)
 * is permanently active for template_upgrade_logs and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, and that TemplateUpgradeLogService::apply()/
 * rollback() — each now opening a SECOND, SEPARATE, SEQUENTIAL
 * runWithFirmContext() wrap placed AFTER
 * TemplatePackInstallationService::install() returns — function
 * correctly end-to-end under FORCE with BOTH installed_template_packs
 * and template_upgrade_logs forced at once.
 *
 * installed_template_pack_id is, unlike template_pack_version_id (a
 * confirmed genuinely global/exempt catalog table with no firm_id
 * column, unaffected by this migration), a real firm-scoped foreign key
 * — installed_template_packs is itself FORCE RLS enabled as of
 * Checkpoint 6 — so, matching FirmEntitlementEventsForceRlsActivationTest's
 * firm_entitlement_id finding and PaymentClassificationEventsForceRlsActivationTest's
 * payment_id finding, there IS a genuine transitive cross-firm mismatch
 * risk here: RLS's single-column policy validates only this row's own
 * firm_id, never that installed_template_pack_id (or rollback_of_id,
 * which self-references this same table) transitively belongs to the
 * same firm. See
 * test_firm_a_can_still_create_a_template_upgrade_log_using_a_firm_b_installed_template_pack_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not a false
 * guarantee, which is exactly why TemplateUpgradeLogFactory's own
 * root-cause fix (deriving firm_id/installed_template_pack_id from the
 * SAME InstalledTemplatePack) matters for factory-default safety.
 *
 * template_upgrade_logs carries a `uuid` column (HasPublicUuid) unlike
 * firm_entitlement_events/firm_activation_events — every raw
 * DB::table('template_upgrade_logs')->insert() call below supplies an
 * explicit uuid value, since bypassing Eloquent also bypasses the
 * model-event hook that would otherwise populate it.
 */
class TemplateUpgradeLogsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
    ];

    public function test_every_previously_forced_table_remains_force_row_level_security_enabled(): void
    {
        foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must remain FORCE RLS enabled after this batch."
            );
        }
    }

    public function test_template_upgrade_logs_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'template_upgrade_logs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_template_upgrade_logs_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_upgrade_logs'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'template_upgrade_logs must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-five tables (the twenty-four previously forced
     * plus template_upgrade_logs) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     *
     * Narrowly updated by Section 39A-3L, Checkpoint 8, Table Phase C
     * (this repo's twenty-sixth staged FORCE activation batch, covering
     * template_upgrade_previews) to account for that later, legitimate
     * addition — additive only, no existing assertion removed or
     * weakened. The real count is now twenty-six.
     */
    public function test_exactly_twenty_five_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (seat_allocations) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (communication_consents) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table
        // Phase C (communication_consent_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13, Table
        // Phase C (this repo's thirty-first staged FORCE activation
        // batch, covering intake_submissions) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses']);

        $actuallyForced = [];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ((bool) $row->relforcerowsecurity) {
                $actuallyForced[] = $table;
            }
        }

        sort($expectedForced);
        sort($actuallyForced);

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table
        // Phase C (document_requests) for the same reason — additive
        // only, no existing assertion removed or weakened.
        $this->assertSame(37, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (template_upgrade_previews, seat_allocations, document_requests, and communication_consents added on top of this batch\'s own template_upgrade_logs, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'template_upgrade_logs'::regclass"
        );

        $this->assertNotNull($policy, 'The template_upgrade_logs tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the read
     * genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_template_upgrade_logs(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TemplateUpgradeLog::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, TemplateUpgradeLog::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_template_upgrade_logs(): void
    {
        $firm = Firm::factory()->create();
        $installed = $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());
        $toVersion = TemplatePackVersion::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('template_upgrade_logs')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'installed_template_pack_id' => $installed->id,
            'to_version_id' => $toVersion->id,
            'status' => TemplateUpgradeLogStatus::Applied->value,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_template_upgrade_log(): void
    {
        $firmA = Firm::factory()->create();
        $logA = $this->runWithFirmContext($firmA, fn () => TemplateUpgradeLog::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$logA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_template_upgrade_log(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => TemplateUpgradeLog::factory()->forFirm($firmA)->create());
        $logB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradeLog::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($logB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $installed = $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());
        $toVersion = TemplatePackVersion::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $installed, $toVersion) {
            return DB::table('template_upgrade_logs')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'installed_template_pack_id' => $installed->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradeLogStatus::Applied->value,
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_template_upgrade_log_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());
        $toVersion = TemplatePackVersion::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $installedB, $toVersion) {
            DB::table('template_upgrade_logs')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'installed_template_pack_id' => $installedB->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradeLogStatus::Applied->value,
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_template_upgrade_log(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $logB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradeLog::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($logB) {
            DB::table('template_upgrade_logs')->where('id', $logB->id)->update(['status' => TemplateUpgradeLogStatus::RolledBack->value]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->find($logB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            TemplateUpgradeLogStatus::Applied,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s template_upgrade_logs row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_template_upgrade_log(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $logB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradeLog::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($logB) {
            DB::table('template_upgrade_logs')->where('id', $logB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->find($logB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s template_upgrade_logs row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_template_upgrade_log_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $logB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradeLog::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $logB) {
            return DB::table('template_upgrade_logs')->where('id', $logB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s template upgrade log to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->find($logB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock: RLS validates only this row's own firm_id
     * — a raw insert whose firm_id matches the active context still
     * succeeds even when installed_template_pack_id points at ANOTHER
     * firm's installed_template_packs row. This is a documented
     * residual DATABASE-CONSTRAINT gap, not something RLS itself
     * closes — never to be described as blocked.
     */
    public function test_firm_a_can_still_create_a_template_upgrade_log_using_a_firm_b_installed_template_pack_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());
        $toVersion = TemplatePackVersion::factory()->create();

        $mismatchedLogId = $this->runWithFirmContext($firmA, function () use ($firmA, $installedB, $toVersion) {
            return DB::table('template_upgrade_logs')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'installed_template_pack_id' => $installedB->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradeLogStatus::Applied->value,
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedLogId,
            'RLS only checks the row\'s own firm_id — a transitive installed_template_pack_id/firm_id mismatch is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: TemplateUpgradeLogFactory::definition()
     * derives firm_id and installed_template_pack_id from ONE shared
     * InstalledTemplatePack::factory()->create() call — proving those
     * two columns can never disagree about which firm they refer to,
     * and that the factory's context-hold create() override lets a
     * bare ->create() call succeed even from outside any already-active
     * tenant context.
     */
    public function test_template_upgrade_log_factory_default_creation_is_internally_consistent(): void
    {
        $log = TemplateUpgradeLog::factory()->create();

        $this->assertNotNull($log->id);
        $this->assertNotNull($log->firm_id);
        $this->assertNotNull($log->installed_template_pack_id);

        $result = $this->runWithFirmContext($log->firm, function () use ($log) {
            return [
                'log' => TemplateUpgradeLog::withoutGlobalScopes()->find($log->id),
                'installed' => InstalledTemplatePack::withoutGlobalScopes()->find($log->installed_template_pack_id),
            ];
        });

        $this->assertNotNull($result['log'], 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertNotNull($result['installed']);
        $this->assertSame(
            $log->firm_id,
            $result['installed']->firm_id,
            'firm_id and installed_template_pack_id must derive from the SAME InstalledTemplatePack — they must never disagree about which firm they refer to.'
        );
    }

    /**
     * Explicit related-model factory state correctness: forFirm() must
     * re-derive installed_template_pack_id from a NEW InstalledTemplatePack
     * created for the exact firm given — not merely override the bare
     * firm_id column while installed_template_pack_id still points at
     * some other independently-spun-up firm's pack.
     */
    public function test_template_upgrade_log_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $log = $this->runWithFirmContext($firm, fn () => TemplateUpgradeLog::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $log->firm_id);

        $result = $this->runWithFirmContext($firm, function () use ($log) {
            return [
                'log' => TemplateUpgradeLog::withoutGlobalScopes()->find($log->id),
                'installed' => InstalledTemplatePack::withoutGlobalScopes()->find($log->installed_template_pack_id),
            ];
        });

        $this->assertNotNull($result['log']);
        $this->assertNotNull($result['installed'], 'installed_template_pack_id must point at a row that actually belongs to the same firm.');
        $this->assertSame($firm->id, $result['installed']->firm_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TemplateUpgradeLog::factory()->forFirm($firm)->create());

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
     * End-to-end proof that TemplateUpgradeLogService::apply() functions
     * correctly under FORCE with BOTH installed_template_packs and
     * template_upgrade_logs forced simultaneously — install() (called
     * OUTSIDE of apply()'s own second context wrap) and apply()'s own
     * second, separate runWithFirmContext() wrap (covering the direct
     * $preview->update()/TemplateUpgradeLog::create() writes) must each
     * clear their own context in their own finally block, and the
     * resulting row must be genuinely persisted and readable back under
     * the same firm's context.
     */
    public function test_the_apply_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();
        $installationService = new TemplatePackInstallationService();
        $previewService = new TemplateUpgradePreviewService();
        $service = new TemplateUpgradeLogService($installationService);

        $installed = $installationService->install($firm, $v1);
        $this->assertNoDatabaseTenantContext();

        $preview = $previewService->preview($installed, $v2);
        $log = $service->apply($preview);
        $this->assertNoDatabaseTenantContext('apply() must clear its own second context wrap in a finally block before returning.');

        $this->assertSame(TemplateUpgradeLogStatus::Applied, $log->status);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->find($log->id),
        );

        $this->assertNotNull($persisted, 'apply() must actually persist the new template_upgrade_logs row to the database.');
        $this->assertSame(TemplateUpgradeLogStatus::Applied, $persisted->status);
        $this->assertSame($v2->id, $persisted->to_version_id);
    }

    /**
     * Same end-to-end proof as apply() above, for rollback() — proving
     * the append-only guarantee (the original Applied row is never
     * mutated) holds true under FORCE, and that both of rollback()'s
     * own nested-but-sequential context wraps (install()'s and
     * rollback()'s own second wrap) clear correctly.
     */
    public function test_the_rollback_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();
        $installationService = new TemplatePackInstallationService();
        $previewService = new TemplateUpgradePreviewService();
        $service = new TemplateUpgradeLogService($installationService);

        $installed = $installationService->install($firm, $v1);
        $preview = $previewService->preview($installed, $v2);
        $appliedLog = $service->apply($preview);

        $rollbackLog = $service->rollback($appliedLog);
        $this->assertNoDatabaseTenantContext('rollback() must clear its own second context wrap in a finally block before returning.');

        $this->assertSame(TemplateUpgradeLogStatus::RolledBack, $rollbackLog->status);
        $this->assertSame($appliedLog->id, $rollbackLog->rollback_of_id);

        $persistedAppliedLog = $this->runWithFirmContext(
            $firm,
            fn () => TemplateUpgradeLog::withoutGlobalScopes()->find($appliedLog->id),
        );

        $this->assertNotNull($persistedAppliedLog);
        $this->assertSame(
            TemplateUpgradeLogStatus::Applied,
            $persistedAppliedLog->status,
            'The original Applied row must never be mutated by rollback() — this is append-only.'
        );
        $this->assertNull($persistedAppliedLog->rollback_of_id);
    }

    /**
     * template_pack_versions must remain globally readable and
     * unaffected by this migration — it is a confirmed genuinely
     * global/exempt catalog table (no firm_id column) and this batch
     * changes nothing about it.
     */
    public function test_template_pack_version_relation_remains_globally_readable_and_unaffected(): void
    {
        $firm = Firm::factory()->create();
        $log = $this->runWithFirmContext($firm, fn () => TemplateUpgradeLog::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $toVersion = TemplatePackVersion::find($log->to_version_id);

        $this->assertNotNull($toVersion, 'template_pack_versions is exempt/global — it must remain readable with no tenant context at all.');

        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_pack_versions'");
        $this->assertFalse((bool) $row->relforcerowsecurity, 'template_pack_versions must remain exempt from FORCE RLS.');
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself. Also proves rollback affects ONLY this one table — every
     * other previously-forced table must be untouched.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930007_force_rls_on_template_upgrade_logs_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'template_upgrade_logs'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while template_upgrade_logs is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'template_upgrade_logs'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_upgrade_logs'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Twenty-four previously forced tables plus template_upgrade_logs
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses installed_template_packs
     * (this table's own conceptual relative — template_upgrade_logs
     * carries installed_template_pack_id) as the companion table.
     */
    public function test_template_upgrade_logs_is_isolated_independently_and_simultaneously_with_installed_template_packs(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $logA = $this->runWithFirmContext($firmA, fn () => TemplateUpgradeLog::factory()->forFirm($firmA)->create());
        $logB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradeLog::factory()->forFirm($firmB)->create());

        $installedA = $this->runWithFirmContext($firmA, fn () => InstalledTemplatePack::factory()->forFirm($firmA)->create());
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'template_upgrade_logs' => TemplateUpgradeLog::withoutGlobalScopes()->pluck('id')->all(),
            'installed_template_packs' => InstalledTemplatePack::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$logA->id], $resultA['template_upgrade_logs']);
        $this->assertNotContains($logB->id, $resultA['template_upgrade_logs']);
        $this->assertContains($installedA->id, $resultA['installed_template_packs']);
        $this->assertNotContains($installedB->id, $resultA['installed_template_packs']);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
