<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use Tests\TestCase;

/**
 * PermissionBoundaryFirewallTest — proves Section 27 stayed within its
 * declared implementation boundary: no migrations, no new tables, no
 * new role system, no UI, no real execution in any new mapping
 * service, and no protected role/access policy file was modified.
 */
class PermissionBoundaryFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'PermissionMatrixMappingService.php',
        'EmergencyAccessGovernanceGapService.php',
        'LegalSpecialistConsistencyMappingService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send',
    ];

    private const PROTECTED_FILES = [
        'app/Enums/FirmUserRole.php',
        'app/Enums/PlatformRoleCode.php',
        'app/Models/PlatformRole.php',
        'app/Models/PlatformAdmin.php',
        'app/Models/FirmUser.php',
        'app/Models/Client.php',
        'app/Services/PlatformStaffAccessPolicyService.php',
        'app/Services/MatterAccessPolicyService.php',
        'app/Services/TrustAccessPolicyService.php',
        'app/Services/SupportAccessPolicyService.php',
        'app/Services/SupportAccessRequestService.php',
        'app/Services/SupportAccessSessionService.php',
        'app/Services/LegalSpecialistBoundaryPolicyService.php',
        'app/Services/HighRiskPlatformChangePolicyService.php',
        'app/Enums/HighRiskChangeType.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 27 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_org_role_enum_or_organization_users_table_model_or_migration_was_added(): void
    {
        $this->assertFileDoesNotExist(app_path('Enums/OrganizationRole.php'));
        $this->assertFileDoesNotExist(app_path('Models/OrganizationUser.php'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('organization_users'));

        $migrationMatches = glob(database_path('migrations/*organization_user*'));
        $this->assertEmpty($migrationMatches, 'No organization_user* migration file may exist: '.implode(', ', $migrationMatches ?: []));
    }

    public function test_no_forbidden_execution_or_network_token_appears_in_any_new_mapping_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 27 service file missing: {$filename}");

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
            'PermissionMatrixMappingService', 'EmergencyAccessGovernanceGapService',
            'LegalSpecialistConsistencyMappingService',
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
                        "Section 27 must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_protected_role_and_access_policy_files_were_not_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 27 must not modify protected files, but found changes to: '.implode(', ', $touched));
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
