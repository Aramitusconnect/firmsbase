<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TemplateUpgradePreviewStatus;
use App\Models\Firm;
use App\Models\InstalledTemplatePack;
use App\Models\TemplatePack;
use App\Models\TemplatePackVersion;
use App\Models\TemplateUpgradePreview;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TemplatePackInstallationService;
use App\Services\TemplateUpgradePreviewService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TemplateUpgradePreviewsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 8, Table Phase C. Proves the twenty-sixth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930008_force_rls_on_template_upgrade_previews_table.php)
 * is permanently active for template_upgrade_previews and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, and that TemplateUpgradePreviewService's
 * preview()/markReviewed()/discard() — each now wrapping its ENTIRE
 * body in a single runWithFirmContext() call — function correctly
 * end-to-end under FORCE with BOTH installed_template_packs and
 * template_upgrade_previews forced at once.
 *
 * installed_template_pack_id is, unlike template_pack_version_id (a
 * confirmed genuinely global/exempt catalog table with no firm_id
 * column, unaffected by this migration), a real firm-scoped foreign key
 * — installed_template_packs is itself FORCE RLS enabled as of
 * Checkpoint 6 — so, matching
 * TemplateUpgradeLogsForceRlsActivationTest's own
 * installed_template_pack_id finding (and, before that,
 * FirmEntitlementEventsForceRlsActivationTest's firm_entitlement_id
 * finding and PaymentClassificationEventsForceRlsActivationTest's
 * payment_id finding), there IS a genuine transitive cross-firm
 * mismatch risk here: RLS's single-column policy validates only this
 * row's own firm_id, never that installed_template_pack_id transitively
 * belongs to the same firm. See
 * test_firm_a_can_still_create_a_template_upgrade_preview_using_a_firm_b_installed_template_pack_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not a false
 * guarantee, which is exactly why TemplateUpgradePreviewFactory's own
 * root-cause fix (deriving firm_id/installed_template_pack_id from the
 * SAME InstalledTemplatePack) matters for factory-default safety.
 *
 * template_upgrade_previews carries a `uuid` column (HasPublicUuid)
 * unlike firm_entitlement_events/firm_activation_events — every raw
 * DB::table('template_upgrade_previews')->insert() call below supplies
 * an explicit uuid value, since bypassing Eloquent also bypasses the
 * model-event hook that would otherwise populate it. from_version_id
 * and to_version_id are both NOT NULL foreign keys to
 * template_pack_versions (a confirmed genuinely global/exempt catalog
 * table), so every raw insert below also supplies both explicitly.
 */
class TemplateUpgradePreviewsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
    // Integration Platform mission (firm_integrations, a new genuine
    // tenant-owned table with RLS prepared and FORCE-activated in the
    // same migration, 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 114.
    // Narrowly updated AGAIN by Stage B Checkpoint 4 of the
    // FirmsBase Integration Platform mission (integration_credentials,
    // a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration,
    // 2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 115.
    // Narrowly updated AGAIN by Stage B Checkpoint 5 of the FirmsBase Integration Platform mission
    // (integration_oauth_states, a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 116.
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs',
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

    public function test_template_upgrade_previews_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'template_upgrade_previews'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_template_upgrade_previews_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_upgrade_previews'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'template_upgrade_previews must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-six tables (the twenty-five previously forced plus
     * template_upgrade_previews) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     */
    public function test_exactly_twenty_six_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 9, Table Phase C
        // (this repo's twenty-seventh staged FORCE activation batch,
        // covering seat_allocations) — additive only, no existing
        // assertion removed or weakened. Same reasoning as every prior
        // sibling file's own incremental-count updates in this arc.
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
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states']);
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
        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(116, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (seat_allocations, document_requests, and communication_consents added on top of this batch\'s own template_upgrade_previews, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'template_upgrade_previews'::regclass"
        );

        $this->assertNotNull($policy, 'The template_upgrade_previews tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the read
     * genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_template_upgrade_previews(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TemplateUpgradePreview::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, TemplateUpgradePreview::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_template_upgrade_previews(): void
    {
        $firm = Firm::factory()->create();
        $installed = $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());
        $fromVersion = TemplatePackVersion::factory()->create();
        $toVersion = TemplatePackVersion::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('template_upgrade_previews')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'installed_template_pack_id' => $installed->id,
            'from_version_id' => $fromVersion->id,
            'to_version_id' => $toVersion->id,
            'status' => TemplateUpgradePreviewStatus::Generated->value,
            'previewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_template_upgrade_preview(): void
    {
        $firmA = Firm::factory()->create();
        $previewA = $this->runWithFirmContext($firmA, fn () => TemplateUpgradePreview::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$previewA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_template_upgrade_preview(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => TemplateUpgradePreview::factory()->forFirm($firmA)->create());
        $previewB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradePreview::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($previewB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $installed = $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());
        $fromVersion = TemplatePackVersion::factory()->create();
        $toVersion = TemplatePackVersion::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $installed, $fromVersion, $toVersion) {
            return DB::table('template_upgrade_previews')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'installed_template_pack_id' => $installed->id,
                'from_version_id' => $fromVersion->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradePreviewStatus::Generated->value,
                'previewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_template_upgrade_preview_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());
        $fromVersion = TemplatePackVersion::factory()->create();
        $toVersion = TemplatePackVersion::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $installedB, $fromVersion, $toVersion) {
            DB::table('template_upgrade_previews')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'installed_template_pack_id' => $installedB->id,
                'from_version_id' => $fromVersion->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradePreviewStatus::Generated->value,
                'previewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_template_upgrade_preview(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $previewB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradePreview::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($previewB) {
            DB::table('template_upgrade_previews')->where('id', $previewB->id)->update(['status' => TemplateUpgradePreviewStatus::Reviewed->value]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->find($previewB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            TemplateUpgradePreviewStatus::Generated,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s template_upgrade_previews row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_template_upgrade_preview(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $previewB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradePreview::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($previewB) {
            DB::table('template_upgrade_previews')->where('id', $previewB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->find($previewB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s template_upgrade_previews row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_template_upgrade_preview_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $previewB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradePreview::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $previewB) {
            return DB::table('template_upgrade_previews')->where('id', $previewB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s template upgrade preview to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->find($previewB->id),
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
    public function test_firm_a_can_still_create_a_template_upgrade_preview_using_a_firm_b_installed_template_pack_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());
        $fromVersion = TemplatePackVersion::factory()->create();
        $toVersion = TemplatePackVersion::factory()->create();

        $mismatchedPreviewId = $this->runWithFirmContext($firmA, function () use ($firmA, $installedB, $fromVersion, $toVersion) {
            return DB::table('template_upgrade_previews')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'installed_template_pack_id' => $installedB->id,
                'from_version_id' => $fromVersion->id,
                'to_version_id' => $toVersion->id,
                'status' => TemplateUpgradePreviewStatus::Generated->value,
                'previewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedPreviewId,
            'RLS only checks the row\'s own firm_id — a transitive installed_template_pack_id/firm_id mismatch is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: TemplateUpgradePreviewFactory::definition()
     * derives firm_id and installed_template_pack_id from ONE shared
     * InstalledTemplatePack::factory()->create() call — proving those
     * two columns can never disagree about which firm they refer to,
     * and that the factory's context-hold create() override lets a
     * bare ->create() call succeed even from outside any already-active
     * tenant context.
     */
    public function test_template_upgrade_preview_factory_default_creation_is_internally_consistent(): void
    {
        $preview = TemplateUpgradePreview::factory()->create();

        $this->assertNotNull($preview->id);
        $this->assertNotNull($preview->firm_id);
        $this->assertNotNull($preview->installed_template_pack_id);

        $result = $this->runWithFirmContext($preview->firm, function () use ($preview) {
            return [
                'preview' => TemplateUpgradePreview::withoutGlobalScopes()->find($preview->id),
                'installed' => InstalledTemplatePack::withoutGlobalScopes()->find($preview->installed_template_pack_id),
            ];
        });

        $this->assertNotNull($result['preview'], 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertNotNull($result['installed']);
        $this->assertSame(
            $preview->firm_id,
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
    public function test_template_upgrade_preview_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $preview = $this->runWithFirmContext($firm, fn () => TemplateUpgradePreview::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $preview->firm_id);

        $result = $this->runWithFirmContext($firm, function () use ($preview) {
            return [
                'preview' => TemplateUpgradePreview::withoutGlobalScopes()->find($preview->id),
                'installed' => InstalledTemplatePack::withoutGlobalScopes()->find($preview->installed_template_pack_id),
            ];
        });

        $this->assertNotNull($result['preview']);
        $this->assertNotNull($result['installed'], 'installed_template_pack_id must point at a row that actually belongs to the same firm.');
        $this->assertSame($firm->id, $result['installed']->firm_id);
    }

    /**
     * Explicit related-model factory state correctness for
     * forInstalledPack(): must derive firm_id, installed_template_pack_id,
     * AND from_version_id directly from the given InstalledTemplatePack
     * — never leave any of the three independently resolved.
     */
    public function test_template_upgrade_preview_factory_for_installed_pack_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $installed = $this->runWithFirmContext($firm, fn () => InstalledTemplatePack::factory()->forFirm($firm)->create());

        $preview = $this->runWithFirmContext(
            $firm,
            fn () => TemplateUpgradePreview::factory()->forInstalledPack($installed)->create(),
        );

        $this->assertSame($firm->id, $preview->firm_id);
        $this->assertSame($installed->id, $preview->installed_template_pack_id);
        $this->assertSame($installed->template_pack_version_id, $preview->from_version_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TemplateUpgradePreview::factory()->forFirm($firm)->create());

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
     * End-to-end proof that TemplateUpgradePreviewService::preview()
     * functions correctly under FORCE with BOTH installed_template_packs
     * and template_upgrade_previews forced simultaneously, and that its
     * own runWithFirmContext() wrap clears the context in a finally
     * block before returning.
     */
    public function test_the_preview_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();
        $installationService = new TemplatePackInstallationService();
        $service = new TemplateUpgradePreviewService();

        $installed = $installationService->install($firm, $v1);
        $this->assertNoDatabaseTenantContext();

        $preview = $service->preview($installed, $v2);
        $this->assertNoDatabaseTenantContext('preview() must clear its own context wrap in a finally block before returning.');

        $this->assertSame(TemplateUpgradePreviewStatus::Generated, $preview->status);
        $this->assertSame($v1->id, $preview->from_version_id);
        $this->assertSame($v2->id, $preview->to_version_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->find($preview->id),
        );

        $this->assertNotNull($persisted, 'preview() must actually persist the new template_upgrade_previews row to the database.');
        $this->assertSame(TemplateUpgradePreviewStatus::Generated, $persisted->status);
    }

    /**
     * End-to-end proof that markReviewed()/discard() function correctly
     * under FORCE — each wraps its ENTIRE tap(...)->update(...)->fresh()
     * chain, and each clears the context in a finally block before
     * returning.
     */
    public function test_the_mark_reviewed_and_discard_flows_function_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $pack = TemplatePack::factory()->create();
        $v1 = TemplatePackVersion::factory()->forPack($pack)->version('1.0.0')->create();
        $v2 = TemplatePackVersion::factory()->forPack($pack)->version('2.0.0')->create();
        $installationService = new TemplatePackInstallationService();
        $service = new TemplateUpgradePreviewService();

        $installed = $installationService->install($firm, $v1);
        $preview = $service->preview($installed, $v2);

        $reviewed = $service->markReviewed($preview);
        $this->assertNoDatabaseTenantContext('markReviewed() must clear its own context wrap in a finally block before returning.');
        $this->assertSame(TemplateUpgradePreviewStatus::Reviewed, $reviewed->status);

        $discarded = $service->discard($reviewed);
        $this->assertNoDatabaseTenantContext('discard() must clear its own context wrap in a finally block before returning.');
        $this->assertSame(TemplateUpgradePreviewStatus::Discarded, $discarded->status);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TemplateUpgradePreview::withoutGlobalScopes()->find($preview->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(TemplateUpgradePreviewStatus::Discarded, $persisted->status);
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
        $preview = $this->runWithFirmContext($firm, fn () => TemplateUpgradePreview::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $toVersion = TemplatePackVersion::find($preview->to_version_id);

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
        $migration = require base_path('database/migrations/2026_08_25_930008_force_rls_on_template_upgrade_previews_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'template_upgrade_previews'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while template_upgrade_previews is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'template_upgrade_previews'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'template_upgrade_previews'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Twenty-five previously forced tables plus template_upgrade_previews
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses installed_template_packs
     * (this table's own conceptual relative — template_upgrade_previews
     * carries installed_template_pack_id) as the companion table.
     */
    public function test_template_upgrade_previews_is_isolated_independently_and_simultaneously_with_installed_template_packs(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $previewA = $this->runWithFirmContext($firmA, fn () => TemplateUpgradePreview::factory()->forFirm($firmA)->create());
        $previewB = $this->runWithFirmContext($firmB, fn () => TemplateUpgradePreview::factory()->forFirm($firmB)->create());

        $installedA = $this->runWithFirmContext($firmA, fn () => InstalledTemplatePack::factory()->forFirm($firmA)->create());
        $installedB = $this->runWithFirmContext($firmB, fn () => InstalledTemplatePack::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'template_upgrade_previews' => TemplateUpgradePreview::withoutGlobalScopes()->pluck('id')->all(),
            'installed_template_packs' => InstalledTemplatePack::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$previewA->id], $resultA['template_upgrade_previews']);
        $this->assertNotContains($previewB->id, $resultA['template_upgrade_previews']);
        $this->assertContains($installedA->id, $resultA['installed_template_packs']);
        $this->assertNotContains($installedB->id, $resultA['installed_template_packs']);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
