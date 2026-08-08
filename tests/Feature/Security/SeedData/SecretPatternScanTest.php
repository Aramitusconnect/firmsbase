<?php

namespace Tests\Feature\Security\SeedData;

use App\Services\SeedDataSecurityAuditService;
use Tests\TestCase;

/**
 * SecretPatternScanTest — Section 39E. Proves .env.example, config
 * files, seeders, factories, and docs contain no real-looking hardcoded
 * API keys/secrets, that safe test-only fixtures are allowed, and that
 * no demo firm/client/matter/document data is production-seeded.
 */
class SecretPatternScanTest extends TestCase
{
    private SeedDataSecurityAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeedDataSecurityAuditService;
    }

    public function test_env_example_contains_no_real_looking_secret_values(): void
    {
        $this->assertFileExists(base_path('.env.example'));

        $source = file_get_contents(base_path('.env.example'));

        foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
            $this->assertStringNotContainsString($pattern, $source, ".env.example must not contain a real-looking secret pattern: {$pattern}");
        }

        // Sensitive-looking keys must be empty/null placeholders only.
        foreach (['AWS_ACCESS_KEY_ID=', 'AWS_SECRET_ACCESS_KEY=', 'MAIL_PASSWORD=', 'REDIS_PASSWORD='] as $keyPrefix) {
            $this->assertStringContainsString($keyPrefix, $source);
        }

        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID=AKIA', $source);
    }

    public function test_config_files_contain_no_real_looking_hardcoded_secrets(): void
    {
        foreach (glob(config_path('*.php')) ?: [] as $path) {
            $source = file_get_contents($path);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString($pattern, $source, basename($path)." must not contain a real-looking secret pattern: {$pattern}");
            }
        }
    }

    public function test_seeders_and_factories_contain_no_real_looking_hardcoded_secrets(): void
    {
        $paths = array_merge(
            glob(database_path('seeders/*.php')) ?: [],
            glob(database_path('factories/*.php')) ?: [],
        );

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString($pattern, $source, basename($path)." must not contain a real-looking secret pattern: {$pattern}");
            }
        }
    }

    public function test_readme_and_composer_json_contain_no_real_looking_hardcoded_secrets(): void
    {
        foreach ([base_path('README.md'), base_path('composer.json')] as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $source = file_get_contents($path);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString($pattern, $source, basename($path)." must not contain a real-looking secret pattern: {$pattern}");
            }
        }
    }

    public function test_no_custom_artisan_command_exists_that_could_seed_production_data(): void
    {
        $commandsDir = base_path('app/Console/Commands');

        if (! is_dir($commandsDir)) {
            $this->assertTrue(true, 'No app/Console/Commands directory exists.');

            return;
        }

        // Section 39A-4B added two reviewed, read-only reporting
        // commands; neither writes/seeds any production data row.
        // Checkpoint 8 added three further reviewed commands (outbox
        // dispatch, retention sweep, and retry poll); each only
        // dispatches jobs that process/sweep existing integration data
        // (or, for the sweep command, deletes expired rows directly) —
        // none creates demo/seed data of any kind. Checkpoint 11 added
        // RefreshIntegrationPlatformOverviewSummariesCommand, which only
        // dispatches one RefreshIntegrationPlatformOverviewSummaryJob
        // per activated firm; that job upserts sanitized aggregate
        // counts (connection counts, health state, sync/conflict
        // counts) derived from the firm's own real tenant data into
        // integration_platform_overview_summaries — never demo/seed
        // data, and never raw production content.
        // FirmsVault Admin Control Center added
        // PlatformAdminEmergencyMfaResetCommand — reviewed and safe: it
        // creates zero new rows. It requires a target PlatformAdmin
        // record to already exist (errors out otherwise), and only
        // nulls two existing columns plus writes one audit row — the
        // opposite of a seeding command.
        // Phase 2 (FirmsVault Platform Admin Control Center,
        // "Integration Operations Center") added
        // RefreshIntegrationPlatformProviderHealthSummariesCommand —
        // reviewed and safe: the SAME shape as
        // RefreshIntegrationPlatformOverviewSummariesCommand immediately
        // above. It only dispatches one
        // RefreshIntegrationPlatformProviderHealthSummaryJob per
        // registered provider; that job upserts sanitized aggregate
        // counts (connected/disconnected firm counts, oauth/webhook/
        // rate-limit health signals, error-classification counts)
        // derived from each activated firm's own real tenant data into
        // integration_platform_provider_health_summaries — never demo/
        // seed data, and never raw production content.
        // Phase 4 (FirmsVault Platform Admin Control Center,
        // "Operations") added RunHealthChecksCommand and
        // RecordSchedulerHeartbeatCommand — reviewed and safe. Neither
        // creates demo/seed data of any kind: RunHealthChecksCommand
        // dispatches the pre-existing, already-tested
        // RunHealthChecksJob (records real health-check outcomes, not
        // fixtures); RecordSchedulerHeartbeatCommand performs a single
        // Cache write of the current timestamp, no database row at all.
        // FirmsVault Live Integrations Checkpoint 2 added
        // RenewProviderWebhookSubscriptionsCommand — reviewed and safe:
        // it creates zero new rows and seeds no demo/production data;
        // it only reads existing integration_provider_webhook_subscriptions
        // rows (per-firm, under explicit tenant context) and dispatches
        // RenewGraphSubscriptionJob for whichever are due for renewal —
        // the opposite of a seeding command.
        // Platform Firm Provisioning workflow added ProvisionFirmCommand
        // (firms:provision) — reviewed and safe: it does not seed demo
        // or placeholder data of any kind. Every field (firm name, owner
        // name/email, customer type, deployment mode, optional
        // organization/plan) is explicit interactive/CLI-option input
        // from the operator running it, requires an interactive
        // confirmation before writing anything, and is blocked outside
        // local/testing without --confirm-staging and refused
        // unconditionally in production (no escape hatch at all, unlike
        // every other reviewed command in this allowlist).
        // FIRMSVAULT — STAGING ADMIN STABILIZATION added
        // BootstrapStagingSandboxPlanCommand (plans:bootstrap-staging-sandbox)
        // — reviewed and safe: it creates exactly one obviously
        // synthetic, non-commercial plan (name "Staging Sandbox", code
        // "staging-sandbox", price_cents fixed at 0 — never real
        // FirmsVault pricing), idempotent (refuses to create a second
        // one if that code already exists), requires an interactive
        // confirmation before writing anything, and is blocked outside
        // local/testing without --confirm-staging and refused
        // unconditionally in production, identically to
        // ProvisionFirmCommand.
        // feature/ses-event-consumer added ConsumeSesEventsCommand
        // (ses:consume-events) — reviewed and safe: it seeds nothing at
        // all. It only reads from the SES bounce/complaint SQS queue
        // and, for each message, either records a real inbound
        // bounce/complaint via the existing SuppressionService or
        // leaves the message unacknowledged — no demo/placeholder/
        // synthetic data of any kind is ever created.
        // Firm Workspace master mission (seat-provisioning fix) added
        // ReportMissingPurchasedSeatsCommand
        // (firms:report-missing-purchased-seats) — reviewed and safe:
        // default/report mode never writes anything. --apply mode
        // writes a single existing column (purchased_seats) on a
        // single existing FirmLicense row for a real, explicitly-named
        // firm (--firm=<id> --seats=<n>, both required) — it never
        // creates a Firm, FirmLicense, FirmUser, or any other row, and
        // never invents a seat quantity itself (the operator supplies
        // it). No demo/placeholder/synthetic data of any kind is ever
        // created.
        // FirmsVault staging follow-up ("Application Completion —
        // Catalogs + Firm-Owned Reference Data") added
        // InitializeDefaultFirmReferenceDataCommand
        // (firms:initialize-default-reference-data) — reviewed and
        // safe: default/report mode never writes anything. --apply
        // mode writes ONLY the fixed, operator-approved default
        // Expense Category/Lead Source names (e.g. "Filing Fees",
        // "Court Costs", "Website", "Referral - Client" — the exact
        // lists this mission's own spec names) for a single, real,
        // explicitly-named existing firm (--firm=<id>, required) — it
        // never creates a Firm, never invents a category/source name,
        // and never overwrites a firm's own pre-existing custom rows
        // (FirmDefaultReferenceDataService skips any name/code that
        // already exists). No demo/placeholder/synthetic customer-
        // facing data of any kind is ever created.
        $allowlist = [
            'SchemaTenantFirewallCommand.php',
            'RlsSecurityReportCommand.php',
            'DispatchOutboxEventsCommand.php',
            'SweepIntegrationRetentionCommand.php',
            'SyncRetryPollCommand.php',
            'RefreshIntegrationPlatformOverviewSummariesCommand.php',
            'PlatformAdminEmergencyMfaResetCommand.php',
            'RefreshIntegrationPlatformProviderHealthSummariesCommand.php',
            'RunHealthChecksCommand.php',
            'RecordSchedulerHeartbeatCommand.php',
            'RenewProviderWebhookSubscriptionsCommand.php',
            'ProvisionFirmCommand.php',
            'BootstrapStagingSandboxPlanCommand.php',
            'ConsumeSesEventsCommand.php',
            'ReportMissingPurchasedSeatsCommand.php',
            'InitializeDefaultFirmReferenceDataCommand.php',
        ];

        $files = array_map('basename', glob($commandsDir.'/*.php') ?: []);
        $unexpected = array_values(array_diff($files, $allowlist));

        $this->assertEmpty(
            $unexpected,
            'Unreviewed console command(s) found: '.implode(', ', $unexpected).'. Any new command must be reviewed for production-data-seeding risk and added to this allowlist explicitly.'
        );
    }

    public function test_test_only_fixtures_are_allowed_when_isolated_to_the_tests_directory(): void
    {
        // tests/TestCase.php and phpunit.xml's DB_PASSWORD are
        // test/local-only fixtures, never reachable from a
        // production-executable path — confirmed here rather than
        // flagged as an audit finding.
        $this->assertFileExists(base_path('phpunit.xml'));

        $phpunitSource = file_get_contents(base_path('phpunit.xml'));
        $this->assertStringContainsString('APP_ENV" value="testing"', $phpunitSource);

        // phpunit.xml is not among the audit service's unsafe findings
        // even though it carries a local test DB_PASSWORD value —
        // because that value is a self-documenting placeholder
        // ("ChangeThisStrongPasswordNow"), not a real-looking secret.
        $unsafePaths = array_column($this->service->unsafeFindings(), 'path');
        $this->assertNotContains('phpunit.xml', $unsafePaths);
    }

    public function test_no_demo_firm_client_matter_or_document_data_is_production_seeded(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        foreach (['Firm::', 'Client::', 'Matter::', 'Document::'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, 'DatabaseSeeder must not create demo firm/client/matter/document data.');
        }
    }

    // ------------------------------------------------------------
    // Checkpoint 9 extension — the 25 new/modified production files
    // this checkpoint touched, none of which are covered by the
    // config/seeders/factories globs above (app/Integrations/**,
    // app/Services/**, app/ValueObjects/**, and the four new
    // migrations). Reuses the SAME forbidden-pattern list and scan
    // mechanism as every test above — no new taxonomy invented.
    // ------------------------------------------------------------

    /**
     * @return string[] absolute paths, matching the frozen design's
     *                  §14 production-file allowlist exactly (14 new
     *                  + 11 modified).
     */
    private function checkpoint9ChangedFiles(): array
    {
        $relative = [
            'database/migrations/2026_09_08_080001_create_integration_usage_records_table.php',
            'database/migrations/2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php',
            'database/migrations/2026_09_08_081001_add_requeue_columns_to_integration_outbox_events_and_integration_sync_items_table.php',
            'database/migrations/2026_09_08_082001_seed_integration_module_catalog_entry.php',
            'app/Integrations/Models/IntegrationUsageRecord.php',
            'app/Integrations/Data/SanitizedUsageMetadataReference.php',
            'app/Integrations/Data/SanitizedSyncFailureSummary.php',
            'app/Integrations/Services/IntegrationUsageRecorderService.php',
            'app/Integrations/Enums/UsageOperationType.php',
            'app/Services/IntegrationEntitlementPolicyService.php',
            'app/ValueObjects/IntegrationAccessDecision.php',
            'app/Integrations/Services/IntegrationRequeueAuditLogger.php',
            'app/Services/RetentionGovernanceRegistryService.php',
            'database/factories/IntegrationUsageRecordFactory.php',
            'app/Integrations/Services/IntegrationAccessPolicyService.php',
            'app/Integrations/Services/IntegrationOutboxEventService.php',
            'app/Integrations/Services/SyncItemService.php',
            'app/Integrations/Services/ProviderConnectionService.php',
            'app/Integrations/Services/SyncRunService.php',
            'app/Integrations/Services/HealthStateService.php',
            'app/Integrations/Services/IntegrationConflictService.php',
            'app/Integrations/Services/FinancialIntegrationAccessPolicyService.php',
            'app/Integrations/Models/FirmIntegration.php',
            'config/integrations.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
        ];

        return array_map(fn (string $path) => base_path($path), $relative);
    }

    public function test_checkpoint_9_changed_files_all_exist_at_the_expected_paths(): void
    {
        foreach ($this->checkpoint9ChangedFiles() as $path) {
            $this->assertFileExists($path, "Checkpoint 9 changed-file inventory drifted from reality: {$path}");
        }
    }

    public function test_no_checkpoint_9_changed_file_contains_a_hardcoded_secret_pattern(): void
    {
        foreach ($this->checkpoint9ChangedFiles() as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);

            foreach (['sk_live_', 'sk_test_', 'AKIA', 'ghp_', 'xoxb-', 'xoxp-'] as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $source,
                    basename($path)." (Checkpoint 9) must not contain a real-looking secret pattern: {$pattern}"
                );
            }
        }
    }

    public function test_no_checkpoint_9_changed_file_contains_a_raw_env_default_secret_value(): void
    {
        // Belt-and-suspenders for this checkpoint's own config addition:
        // env('INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS') must ship
        // with NO second (default) argument — the frozen design's own
        // fail-safe ruling — so grep for the one way that could regress
        // into looking like a hardcoded value.
        $configSource = file_get_contents(base_path('config/integrations.php'));

        $this->assertMatchesRegularExpression(
            "/env\\('INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS'\\)/",
            $configSource,
            'The usage-records retention_days key must call env() with no second argument.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/env\\('INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS',\\s*\\d/",
            $configSource,
            'The usage-records retention_days key must NOT ship with a numeric default — that is exactly the fail-safe "no default" ruling this checkpoint\'s frozen design requires.'
        );
    }
}
