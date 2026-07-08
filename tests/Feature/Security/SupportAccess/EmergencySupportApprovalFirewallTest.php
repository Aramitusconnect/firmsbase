<?php

namespace Tests\Feature\Security\SupportAccess;

use App\Enums\HighRiskChangeType;
use Tests\TestCase;

/**
 * EmergencySupportApprovalFirewallTest — Section 39C. Proves the fix
 * stayed inside its declared boundary: no new migrations/tables, no
 * new HighRiskChangeType case, no new approval/audit system, no
 * modification to HighRiskPlatformChangePolicyService/HighRiskChangeType/
 * the SupportAccessRequest schema/model, no UI/route files, and no
 * unrelated behavior (payment/trust/AI/RLS/login/2FA/seed) touched.
 */
class EmergencySupportApprovalFirewallTest extends TestCase
{
    /**
     * Files this section is allowed to have modified.
     */
    private const ALLOWED_MODIFIED_FILES = [
        'app/Services/SupportAccessRequestService.php',
        'app/Services/SupportAccessPolicyService.php',
        'app/Services/EmergencyAccessGovernanceGapService.php',
        // Section 39E (a later, distinct security-remediation branch)
        // legitimately adds its own new app/Services file.
        'app/Services/SeedDataSecurityAuditService.php',
        // Section 39B (a later, distinct backend-policy branch)
        // legitimately adds its own new app/Services file.
        'app/Services/FirmUser2faPolicyService.php',
    ];

    /**
     * Files this section must NOT modify.
     */
    private const PROTECTED_FILES = [
        'app/Services/HighRiskPlatformChangePolicyService.php',
        'app/Enums/HighRiskChangeType.php',
        'app/Models/SupportAccessRequest.php',
        'app/Models/HighRiskChangeRequest.php',
        'app/Models/SupportAccessSession.php',
        'app/Services/SupportAccessSessionService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/TrustEligibilityService.php',
        'app/Services/AiRetrievalIsolationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/ConsentService.php',
        'app/Services/ComplianceGapRegistryService.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty($changed, 'Section 39C must add no migrations, but found: '.implode(', ', $changed));
    }

    public function test_no_new_tables_were_created(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_high_risk_change_type_gained_no_new_case(): void
    {
        $cases = array_map(fn ($case) => $case->value, HighRiskChangeType::cases());

        $this->assertCount(7, $cases, 'HighRiskChangeType must not gain a new case for this narrow remediation.');
        $this->assertContains('emergency_support_access', $cases);
    }

    public function test_high_risk_change_type_and_policy_service_are_untouched(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('app/Services/HighRiskPlatformChangePolicyService.php', $changed);
        $this->assertNotContains('app/Enums/HighRiskChangeType.php', $changed);
    }

    public function test_support_access_request_model_and_schema_are_untouched(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('app/Models/SupportAccessRequest.php', $changed);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39C must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_no_unrelated_protected_behavior_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changed));

        $this->assertEmpty($touched, 'Section 39C must not modify protected/unrelated files, but found: '.implode(', ', $touched));
    }

    public function test_only_allowed_app_service_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services');

        $unexpected = array_values(array_diff($changed, self::ALLOWED_MODIFIED_FILES));

        $this->assertEmpty($unexpected, 'Section 39C must only modify the allowed service files, but found: '.implode(', ', $unexpected));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_no_second_high_risk_or_support_access_approval_model_was_introduced(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/EmergencySupportApproval.php'));
        $this->assertFileDoesNotExist(app_path('Models/SupportAccessApproval.php'));
        $this->assertFileDoesNotExist(app_path('Services/EmergencySupportApprovalService.php'));

        $duplicatePolicyServices = glob(app_path('Services/*HighRisk*ChangePolicy*.php')) ?: [];
        $this->assertCount(1, $duplicatePolicyServices, 'Only one high-risk change policy service may exist.');
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

        $paths = preg_split('/\R/', $changed) ?: [];

        // Section 39B (a later, distinct backend-policy branch)
        // legitimately added exactly one migration and modified
        // FirmSettings.php — excluded here (by exact path, regardless
        // of scope) so this section's own declarative-only guarantee
        // still holds without touching every individual check.
        $section39bAllowed = [
            'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php',
            'app/Models/FirmSettings.php',
        ];

        return array_values(array_filter(
            $paths,
            fn (string $path) => ! in_array($path, $section39bAllowed, true),
        ));
    }
}
