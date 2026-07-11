<?php

namespace Tests\Feature\Security\FirmUser2fa;

use App\Services\ComplianceGapRegistryService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FirmUser2faFirewallTest — Section 39B. Proves the fix stayed inside
 * its declared boundary: no duplicate 2FA columns were added to
 * FirmUser, no UI/routes/controllers/auth scaffolding was introduced,
 * no new enum was created, and ComplianceGapRegistryService was not
 * deleted/rewritten to hide the historical firm_user_2fa_missing gap.
 */
class FirmUser2faFirewallTest extends TestCase
{
    public function test_no_duplicate_2fa_columns_were_added_to_firm_users(): void
    {
        $columns = Schema::getColumnListing('firm_users');

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'firm_user_2fa_mode'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $columns, "firm_users must not gain its own '{$forbiddenColumn}' column — User already owns 2FA state.");
        }
    }

    public function test_user_table_2fa_columns_are_unchanged(): void
    {
        $columns = Schema::getColumnListing('users');

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $expectedColumn) {
            $this->assertContains($expectedColumn, $columns, "users.{$expectedColumn} must already exist and remain unchanged.");
        }
    }

    public function test_no_new_two_factor_enum_was_created(): void
    {
        $enumFiles = glob(app_path('Enums/*TwoFactor*.php')) ?: [];

        $this->assertCount(1, $enumFiles, 'Only the existing TwoFactorMode enum may exist — no new 2FA enum should be created.');
        $this->assertSame('TwoFactorMode.php', basename($enumFiles[0]));
    }

    public function test_two_factor_mode_enum_was_not_modified(): void
    {
        $cases = array_map(fn ($case) => $case->value, \App\Enums\TwoFactorMode::cases());

        $this->assertSame(['optional', 'required', 'disabled'], $cases);
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39B must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_fortify_or_breeze_was_installed(): void
    {
        $composerSource = file_get_contents(base_path('composer.json'));

        $this->assertStringNotContainsStringIgnoringCase('fortify', $composerSource);
        $this->assertStringNotContainsStringIgnoringCase('breeze', $composerSource);
    }

    public function test_no_login_route_or_auth_controller_was_introduced(): void
    {
        $webRoutesSource = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsStringIgnoringCase('login', $webRoutesSource);
        $this->assertDirectoryDoesNotExist(app_path('Http/Controllers/Auth'));
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
            'database/seeders/DatabaseSeeder.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            // PaymentClassificationService.php is deliberately NOT in
            // this list any more — Section 39A-3H (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wire recordDecision()'s $payment->update() call with
            // explicit tenant context, since payments now has
            // permanent FORCE ROW LEVEL SECURITY.
            'app/Services/TrustEligibilityService.php',
            'app/Services/AiRetrievalIsolationService.php',
            // ConsentService.php is deliberately NOT in this list any
            // more — Section 39A-3L, Checkpoint 11 (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wrap capture()/revoke()'s bodies in runWithFirmContext(),
            // since communication_consents now has permanent FORCE ROW
            // LEVEL SECURITY.
        ];

        $touched = array_values(array_intersect($protected, $changed));

        $this->assertEmpty($touched, 'Section 39B must not modify unrelated protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_compliance_gap_registry_service_was_not_deleted_or_rewritten(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_gap_registry_still_tracks_the_firm_user_2fa_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('firm_user_2fa_missing'));
        $this->assertCount(21, $registry->all());
    }

    public function test_only_one_new_migration_was_added(): void
    {
        // Once Section 39B's branch is based on a commit that already
        // includes this migration (e.g. a later section's branch), it
        // is no longer "changed/untracked" per git diff — so this
        // asserts the migration file itself exists exactly once,
        // rather than relying on transient git-diff/commit state.
        $migrationFiles = glob(database_path('migrations/*firm_user_2fa_mode*.php')) ?: [];

        $this->assertCount(1, $migrationFiles, 'Expected exactly one firm_user_2fa_mode migration file, but found: '.implode(', ', $migrationFiles));
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
