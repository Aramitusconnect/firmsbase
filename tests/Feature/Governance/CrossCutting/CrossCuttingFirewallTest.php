<?php

namespace Tests\Feature\Governance\CrossCutting;

use Tests\TestCase;

/**
 * CrossCuttingFirewallTest — proves the cross-cutting security/
 * compliance/governance/accessibility mapping package stayed within
 * its declared implementation boundary: no migrations, no UI/routes/
 * controllers, no real network/process/storage/provider execution in
 * any new mapping service.
 */
class CrossCuttingFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'SecurityBaselineMappingService.php',
        'ComplianceReviewGateMappingService.php',
        'AccessibilityCoverageMappingService.php',
        'ClientPortalAccessibilityReadinessService.php',
        'ComplianceGapRegistryService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        "file_get_contents('http", 'file_get_contents("http',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Process::', 'CREATE DATABASE', 'mkdir(',
        'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib', 'Terraform', 'Kubernetes', 'kubectl',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'ClamAV', 'Segment::', 'Mixpanel',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedMigrationPaths();

        $this->assertEmpty(
            $changed,
            'The cross-cutting package must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_forbidden_execution_or_network_token_appears_in_any_new_mapping_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected cross-cutting service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_route_controller_filament_blade_or_livewire_file_was_added(): void
    {
        $markers = [
            'SecurityBaselineMappingService', 'ComplianceReviewGateMappingService',
            'AccessibilityCoverageMappingService', 'ClientPortalAccessibilityReadinessService',
            'ComplianceGapRegistryService', 'GovernanceMappingResult', 'GapRegisterItem',
        ];

        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $dir = base_path($relativeDir);

            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                foreach ($markers as $marker) {
                    $this->assertStringNotContainsString(
                        $marker,
                        $contents,
                        "The cross-cutting package must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_real_scanner_or_provider_call_in_any_new_mapping_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $source = $this->stripComments(file_get_contents(app_path("Services/{$filename}")));

            foreach (['new FakeVirusScanner()', 'implements VirusScanner', 'app(FakeAiProviderAdapter', 'new FakeAiProviderAdapter'] as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} unexpectedly instantiates/implements a scanner or provider adapter directly: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    /**
     * Mirrors the established convention (WebhookNoBusinessWorkflowWiringTest)
     * of proving a directory was untouched via git's changed/untracked
     * file list, scoped to database/migrations.
     *
     * @return array<int, string>
     */
    private function changedOrUntrackedMigrationPaths(): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- database/migrations'
        ));

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        // Section 39B (a later, distinct backend-policy branch)
        // legitimately added exactly one migration. Section 39A-3A (a
        // later, distinct staged-FORCE-activation branch) legitimately
        // added a clients-only FORCE RLS migration.
        return array_values(array_filter(
            $paths,
            fn (string $path) => $path !== 'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php'
                && $path !== 'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php'
                && $path !== 'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'
                && $path !== 'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'
                && $path !== 'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'
                && $path !== 'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'
                && $path !== 'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'
                && $path !== 'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'
                && $path !== 'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'
                // Internal login/panel access wiring (a later, distinct
                // section) legitimately added a migration extending
                // firm_users' RLS policy with a narrow self-lookup
                // clause needed to bootstrap-resolve an authenticated
                // user's own firm from firm_users itself.
                && $path !== 'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // conflict_check_runs-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'
                // Section 39A-3J (this batch, a later, distinct
                // staged-FORCE-activation branch) legitimately added
                // FORCE RLS migrations for lead_sources,
                // consultation_outcomes, firm_leads, and consultations
                // together.
                && $path !== 'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'
                && $path !== 'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'
                && $path !== 'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'
                && $path !== 'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'
                // Section 39A-3K (this batch, a later, distinct
                // staged-FORCE-activation branch) legitimately added
                // FORCE RLS migrations for firm_practice_areas,
                // document_chase_rules, employee_rates, calendar_events,
                // and client_communication_preferences together.
                && $path !== 'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'
                && $path !== 'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'
                && $path !== 'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'
                && $path !== 'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'
                && $path !== 'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'
                // Section 39A-3L, Checkpoint 10, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_requests-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'
                // Section 39A-3L, Checkpoint 11, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a communication_consents-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'
                // Section 39A-3L, Checkpoint 22, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plans-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'
                // Section 39A-3L, Checkpoint 23, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plan_events-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'
                // Section 39A-3L, Checkpoint 24 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a notification_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php',
        ));
    }

    /**
     * Strips PHP comments (// # and block/doc comments) via the real
     * tokenizer so forbidden-token checks only ever see executable
     * code — a token merely mentioned in prose must never fail a
     * firewall test.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }
}
