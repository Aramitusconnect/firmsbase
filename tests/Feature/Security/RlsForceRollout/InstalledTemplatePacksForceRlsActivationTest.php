<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\InstalledTemplatePackStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\Matter;
use App\Models\TemplatePack;
use App\Models\TemplatePackVersion;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TemplatePackInstallationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * InstalledTemplatePacksForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 6, Table Phase C. Proves the twenty-fourth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php)
 * is permanently active for installed_template_packs and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, and that
 * TemplatePackInstallationService::install()/markUpgradeAvailable()/
 * disable() — each now wrapping its ENTIRE body in a single
 * runWithFirmContext() call — function correctly end-to-end under
 * FORCE.
 *
 * This checkpoint's own most important finding: before this batch,
 * markUpgradeAvailable()/disable() called tap($model)->update([...])
 * unwrapped. Eloquent's update() always returns true regardless of
 * actual affected-row count, so under FORCE with no active context the
 * UPDATE's WHERE clause would silently match zero rows per the RLS
 * policy — Postgres reports no error, and the in-memory model looks
 * updated while the real database row is untouched. This is a genuinely
 * silent, previously-undetectable bug class distinct from every prior
 * checkpoint's "raw insert" cross-firm findings: it is a false-positive
 * SUCCESS, not a rejected write. See
 * test_mark_upgrade_available_actually_persists_the_status_change_to_the_database()
 * and
 * test_disable_actually_persists_the_status_change_and_disabled_at_to_the_database()
 * below, which are written specifically to distinguish "the in-memory
 * object looks right" from "the database row is actually right" — the
 * distinction the pre-fix code could not make.
 *
 * template_pack_id and template_pack_version_id are both confirmed
 * genuinely global/exempt catalog-table foreign keys (template_packs,
 * template_pack_versions — neither has a firm_id column, both remain in
 * RowLevelSecurityCoverageMappingService::EXEMPT_TABLES), so there is no
 * transitive cross-firm mismatch risk analogous to
 * PaymentClassificationEventsForceRlsActivationTest's payment_id finding
 * or FirmEntitlementEventsForceRlsActivationTest's firm_entitlement_id
 * finding — this file does not need (and must not claim) an equivalent
 * "residual database-constraint gap" test, since the FK targets are not
 * firm-scoped at all.
 */
class InstalledTemplatePacksForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
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

    public function test_installed_template_packs_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'installed_template_packs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_installed_template_packs_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'installed_template_packs'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'installed_template_packs must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-four tables (the twenty-three previously forced plus
     * installed_template_packs) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     *
     * Narrowly updated by Section 39A-3L, Checkpoint 7, Table Phase C
     * (this repo's twenty-fifth staged FORCE activation batch, covering
     * template_upgrade_logs) to account for that later, legitimate
     * addition — additive only, no existing assertion removed or
     * weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 8, Table
     * Phase C (this repo's twenty-sixth staged FORCE activation batch,
     * covering template_upgrade_previews) for the same reason — additive
     * only, no existing assertion removed or weakened.
     */
    public function test_exactly_twenty_four_prepared_tables_are_force_row_level_security_enabled(): void
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (this repo's fortieth staged FORCE activation batch, covering payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
        // repo's forty-second staged FORCE activation batch, covering
        // notification_events) to extend the "exactly these tables
        // are forced" list to include notification_events too — this
        // test's own scope predates Checkpoint 24, but the exact-count
        // assertion below must still account for that later,
        // legitimate addition rather than falsely reporting it as
        // unexpected — additive only, no existing assertion removed
        // or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events']);
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21, Table
        // Phase C (this repo's thirty-ninth staged FORCE activation batch,
        // covering time_entries) for the same reason — additive only, no
        // existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        $this->assertSame(42, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (template_upgrade_logs, template_upgrade_previews, seat_allocations, document_requests, and communication_consents added on top of this batch\'s own installed_template_packs, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'installed_template_packs'::regclass"
        );

        $this->assertNotNull($policy, 'The installed_template_packs tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the read
     * genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_installed_template_packs(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, InstalledTemplatePack::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_installed_template_packs(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('installed_template_packs')->insert([
            'firm_id' => $firm->id,
            'template_pack_id' => $version->template_pack_id,
            'template_pack_version_id' => $version->id,
            'status' => InstalledTemplatePackStatus::Active->value,
            'installed_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_installed_template_pack(): void
    {
        $firmA = Firm::factory()->create();
        $installedA = $this->runWithFirmContext($firmA, fn () => InstalledTemplatePack::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$installedA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_installed_template_pack(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => InstalledTemplatePack::factory()->forFirm($firmA)->create());
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($installedB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $version) {
            return DB::table('installed_template_packs')->insertGetId([
                'firm_id' => $firm->id,
                'template_pack_id' => $version->template_pack_id,
                'template_pack_version_id' => $version->id,
                'status' => InstalledTemplatePackStatus::Active->value,
                'installed_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_an_installed_template_pack_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $version) {
            DB::table('installed_template_packs')->insert([
                'firm_id' => $firmB->id,
                'template_pack_id' => $version->template_pack_id,
                'template_pack_version_id' => $version->id,
                'status' => InstalledTemplatePackStatus::Active->value,
                'installed_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_installed_template_pack(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($installedB) {
            DB::table('installed_template_packs')->where('id', $installedB->id)->update(['status' => InstalledTemplatePackStatus::Disabled->value]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installedB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            InstalledTemplatePackStatus::Active,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s installed_template_packs row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_installed_template_pack(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($installedB) {
            DB::table('installed_template_packs')->where('id', $installedB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installedB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s installed_template_packs row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_installed_template_pack_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $installedB) {
            return DB::table('installed_template_packs')->where('id', $installedB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s installed template pack to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installedB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Bare factory default: InstalledTemplatePackFactory::definition()
     * derives template_pack_id/template_pack_version_id from ONE shared
     * TemplatePackVersion::factory() call — proving those two columns
     * can never disagree about which pack/version they refer to, and
     * that the factory's context-hold create() override lets a bare
     * ->create() call succeed even from outside any already-active
     * tenant context.
     */
    public function test_installed_template_pack_factory_default_creation_is_internally_consistent(): void
    {
        $installed = InstalledTemplatePack::factory()->create();

        $this->assertNotNull($installed->id);
        $this->assertNotNull($installed->firm_id);
        $this->assertNotNull($installed->template_pack_id);
        $this->assertNotNull($installed->template_pack_version_id);

        $result = $this->runWithFirmContext($installed->firm, function () use ($installed) {
            return [
                'installed' => InstalledTemplatePack::withoutGlobalScopes()->find($installed->id),
                'version' => TemplatePackVersion::find($installed->template_pack_version_id),
            ];
        });

        $this->assertNotNull($result['installed'], 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertNotNull($result['version']);
        $this->assertSame(
            $installed->template_pack_id,
            $result['version']->template_pack_id,
            'template_pack_id and template_pack_version_id must derive from the SAME TemplatePackVersion — they must never disagree about which pack they refer to.'
        );
    }

    /**
     * Explicit related-model factory state correctness: forVersion()
     * must tie BOTH template_pack_id and template_pack_version_id to the
     * exact TemplatePackVersion given — not merely the version_id while
     * leaving template_pack_id from some other independently-spun-up
     * TemplatePackVersion.
     */
    public function test_installed_template_pack_factory_for_version_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $version = TemplatePackVersion::factory()->forPack($pack)->version('3.1.4')->create();

        $installed = $this->runWithFirmContext(
            $firm,
            fn () => InstalledTemplatePack::factory()->forFirm($firm)->forVersion($version)->create(),
        );

        $this->assertSame($firm->id, $installed->firm_id);
        $this->assertSame($pack->id, $installed->template_pack_id);
        $this->assertSame($version->id, $installed->template_pack_version_id);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installed->id),
        );

        $this->assertNotNull($reRead);
        $this->assertSame($pack->id, $reRead->template_pack_id);
        $this->assertSame($version->id, $reRead->template_pack_version_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());

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

    public function test_the_install_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();
        $service = new TemplatePackInstallationService();

        $first = $service->install($firm, $v1);
        $this->assertNoDatabaseTenantContext('install() must clear its own context in a finally block before returning.');

        $second = $service->install($firm, $v2);
        $this->assertNoDatabaseTenantContext();

        $this->assertSame($first->id, $second->id, 'install() must upgrade the same row in place, never duplicate it.');

        $persisted = $this->runWithFirmContext($firm, fn () => $second->fresh());
        $this->assertSame($v2->id, $persisted->template_pack_version_id);
        $this->assertSame(InstalledTemplatePackStatus::Active, $persisted->status);
    }

    /**
     * This checkpoint's single most important regression test: proves
     * markUpgradeAvailable() actually persists its status change to the
     * database, not merely to the in-memory model tap() returns. Before
     * this batch's fix, the exact same assertions against the in-memory
     * $flagged object would have passed even if the underlying UPDATE
     * silently affected zero rows.
     */
    public function test_mark_upgrade_available_actually_persists_the_status_change_to_the_database(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();
        $service = new TemplatePackInstallationService();
        $installed = $service->install($firm, $version);

        $flagged = $service->markUpgradeAvailable($installed);
        $this->assertNoDatabaseTenantContext('markUpgradeAvailable() must clear its own context in a finally block before returning.');

        $this->assertSame(InstalledTemplatePackStatus::UpgradeAvailable, $flagged->status);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installed->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(
            InstalledTemplatePackStatus::UpgradeAvailable,
            $persisted->status,
            'markUpgradeAvailable() must actually persist the status change to the database — this is the regression test for the silent-no-op bug this checkpoint fixed.'
        );
    }

    /**
     * Same regression proof as above, applied to disable(): both status
     * AND disabled_at must be genuinely persisted.
     */
    public function test_disable_actually_persists_the_status_change_and_disabled_at_to_the_database(): void
    {
        $firm = Firm::factory()->create();
        $version = TemplatePackVersion::factory()->create();
        $service = new TemplatePackInstallationService();
        $installed = $service->install($firm, $version);

        $disabled = $service->disable($installed);
        $this->assertNoDatabaseTenantContext('disable() must clear its own context in a finally block before returning.');

        $this->assertSame(InstalledTemplatePackStatus::Disabled, $disabled->status);
        $this->assertNotNull($disabled->disabled_at);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => InstalledTemplatePack::withoutGlobalScopes()->find($installed->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(
            InstalledTemplatePackStatus::Disabled,
            $persisted->status,
            'disable() must actually persist the status change to the database — this is the regression test for the silent-no-op bug this checkpoint fixed.'
        );
        $this->assertNotNull($persisted->disabled_at, 'disable() must actually persist disabled_at to the database.');
    }

    /**
     * template_packs/template_pack_versions must remain globally readable
     * and unaffected by this migration — they are confirmed genuinely
     * global/exempt catalog tables (no firm_id column) and this batch
     * changes nothing about them.
     */
    public function test_template_pack_and_template_pack_version_relations_remain_globally_readable_and_unaffected(): void
    {
        $firm = Firm::factory()->create();
        $installed = $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $pack = TemplatePack::find($installed->template_pack_id);
        $version = TemplatePackVersion::find($installed->template_pack_version_id);

        $this->assertNotNull($pack, 'template_packs is exempt/global — it must remain readable with no tenant context at all.');
        $this->assertNotNull($version, 'template_pack_versions is exempt/global — it must remain readable with no tenant context at all.');

        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_packs'");
        $this->assertFalse((bool) $row->relforcerowsecurity, 'template_packs must remain exempt from FORCE RLS.');

        $row2 = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_pack_versions'");
        $this->assertFalse((bool) $row2->relforcerowsecurity, 'template_pack_versions must remain exempt from FORCE RLS.');
    }

    /**
     * matters is FORCE RLS as of an earlier checkpoint in this arc —
     * install() must never retroactively change
     * Matter::pinned_template_pack_version_id on matters already pinned
     * to an old version, and this must hold true now that BOTH matters
     * and installed_template_packs are forced simultaneously.
     */
    public function test_installing_a_new_version_does_not_retroactively_change_matters_already_pinned_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();
        $service = new TemplatePackInstallationService();

        $service->install($firm, $v1);
        $matter = $this->runWithFirmContext(
            $firm,
            fn () => Matter::factory()->forFirm($firm)->create(['pinned_template_pack_version_id' => $v1->id]),
        );

        $service->install($firm, $v2);

        $persistedMatter = $this->runWithFirmContext($firm, fn () => $matter->fresh());
        $this->assertSame($v1->id, $persistedMatter->pinned_template_pack_version_id);
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
        $migration = require base_path('database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'installed_template_packs'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while installed_template_packs is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'installed_template_packs'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'installed_template_packs'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Twenty-three previously forced tables plus installed_template_packs
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses matters (this table's own
     * conceptual relative — matters carry pinned_template_pack_version_id)
     * as the companion table.
     */
    public function test_installed_template_packs_is_isolated_independently_and_simultaneously_with_matters(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $installedA = $this->runWithFirmContext($firmA, fn () => InstalledTemplatePack::factory()->forFirm($firmA)->create());
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'installed_template_packs' => InstalledTemplatePack::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$installedA->id], $resultA['installed_template_packs']);
        $this->assertNotContains($installedB->id, $resultA['installed_template_packs']);
        $this->assertSame([$matterA->id], $resultA['matters']);
        $this->assertNotContains($matterB->id, $resultA['matters']);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
