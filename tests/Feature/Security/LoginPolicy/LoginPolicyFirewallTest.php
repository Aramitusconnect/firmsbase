<?php

namespace Tests\Feature\Security\LoginPolicy;

use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * LoginPolicyFirewallTest — Section 39D. Proves the fix stayed inside
 * its declared boundary: no routes/controllers/auth scaffolding were
 * introduced, no Fortify/Breeze was installed, no migrations/schema
 * changes were made, and ComplianceGapRegistryService was not deleted/
 * rewritten to hide the historical login_policy_wrappers_missing gap.
 */
class LoginPolicyFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'LoginPolicyService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'DB::insert', 'DB::update', 'DB::delete', 'DB::table(',
        'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send', 'file_put_contents(', 'fopen(', 'unlink(',
        '::create(', '::update(', '::delete(', '->save(',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty($changed, 'Section 39D must add no migrations, but found: '.implode(', ', $changed));
    }

    public function test_no_models_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Models');

        $this->assertEmpty($changed, 'Section 39D must not modify any model, but found changes to: '.implode(', ', $changed));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39D must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        // Section 39A (a later, distinct RLS-activation branch)
        // legitimately added one route-independent middleware file
        // (App\Http\Middleware\ApplyTenantDatabaseContext, not wired to
        // any route or bootstrap/app.php) — narrowly excluded here so
        // Section 39D's own "no middleware" guarantee still holds for
        // everything else.
        $middlewareChanges = array_values(array_filter(
            $this->changedOrUntrackedPaths('app/Http/Middleware'),
            fn (string $path) => $path !== 'app/Http/Middleware/ApplyTenantDatabaseContext.php',
        ));
        $this->assertEmpty($middlewareChanges, 'Section 39D must introduce no middleware surface, but found changes under app/Http/Middleware: '.implode(', ', $middlewareChanges));

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_fortify_or_breeze_was_installed(): void
    {
        $composerSource = file_get_contents(base_path('composer.json'));

        $this->assertStringNotContainsStringIgnoringCase('fortify', $composerSource);
        $this->assertStringNotContainsStringIgnoringCase('breeze', $composerSource);

        $this->assertEmpty($this->changedOrUntrackedPaths('composer.json'));
        $this->assertEmpty($this->changedOrUntrackedPaths('bootstrap/app.php'));
        $this->assertEmpty($this->changedOrUntrackedPaths('bootstrap/providers.php'));
    }

    public function test_no_login_route_or_auth_controller_was_introduced(): void
    {
        $webRoutesSource = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsStringIgnoringCase('login', $webRoutesSource);
        $this->assertFileDoesNotExist(base_path('routes/api.php'));
        $this->assertDirectoryDoesNotExist(app_path('Http/Controllers/Auth'));

        $controllerFiles = glob(app_path('Http/Controllers/*.php')) ?: [];
        $this->assertSame(['Controller.php'], array_map('basename', $controllerFiles));
    }

    public function test_no_protected_domain_behavior_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $protected = [
            'app/Services/HighRiskPlatformChangePolicyService.php',
            'app/Services/SupportAccessPolicyService.php',
            'app/Services/SupportAccessRequestService.php',
            'app/Services/EmergencyAccessGovernanceGapService.php',
            'app/Services/SeedDataSecurityAuditService.php',
            'app/Services/FirmUser2faPolicyService.php',
            'database/seeders/DatabaseSeeder.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'app/Services/PaymentClassificationService.php',
            'app/Services/TrustEligibilityService.php',
            'app/Services/AiRetrievalIsolationService.php',
            'app/Services/ConsentService.php',
            'app/Models/User.php',
            'app/Models/FirmUser.php',
            'app/Models/FirmSettings.php',
        ];

        $touched = array_values(array_intersect($protected, $changed));

        $this->assertEmpty($touched, 'Section 39D must not modify unrelated protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_new_service_contains_no_forbidden_tokens_or_writes(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 39D service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_compliance_gap_registry_service_was_not_deleted_or_rewritten(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_gap_registry_still_tracks_the_login_policy_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('login_policy_wrappers_missing'));
        $this->assertCount(21, $registry->all());
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

    /**
     * Strips PHP comments so forbidden-token checks only ever see
     * executable code — a token merely mentioned in prose must never
     * fail a firewall test.
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
