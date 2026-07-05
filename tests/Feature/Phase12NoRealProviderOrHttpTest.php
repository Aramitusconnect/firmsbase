<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required: no real QuickBooks/OAuth/HTTP/webhook code exists, and no
 * two-way sync code exists, anywhere in Phase 12. Mirrors Phase 11's
 * Phase11NoRealProviderOrFilingTest exactly.
 */
class Phase12NoRealProviderOrHttpTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'QuickBooksClient', 'QuickBooksApi', 'quickbooks-php', 'QuickBooksOnlineSDK',
        'OAuth', 'oauth', 'client_secret', 'access_token', 'refresh_token',
        'GuzzleHttp', 'Http::', 'curl_exec', 'fsockopen', 'file_get_contents(\'http',
        'Webhook', 'webhook',
        'two_way_sync', 'twoWaySync', 'TwoWaySync', 'bidirectional_sync', 'sync_from_quickbooks',
        'pullFromQuickBooks', 'importFromQuickBooks',
    ];

    private const PHASE_12_SERVICE_FILES = [
        'ExpenseCategoryService.php',
        'ChartOfAccountsService.php',
        'ExpenseService.php',
        'ExpenseReceiptService.php',
        'ExpenseApprovalService.php',
        'MatterExpenseService.php',
        'ReimbursableExpenseInvoiceEligibilityService.php',
        'ReimbursableExpenseInvoiceLineService.php',
        'ExpenseReportingService.php',
        'AccountingExportBatchService.php',
        'AccountingExportLineBuilderService.php',
        'AccountingExportSimulationService.php',
        'AccountingExportErrorLogger.php',
        'AccountingEntitlementPolicyService.php',
        'TenantSafeAccountingPolicyService.php',
    ];

    public function test_no_phase_12_service_references_a_real_provider_sdk_oauth_or_network_call(): void
    {
        foreach (self::PHASE_12_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path);

            $source = file_get_contents($path);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }

    public function test_no_filament_blade_livewire_route_or_controller_files_exist_for_phase_12(): void
    {
        $this->assertFalse(is_dir(app_path('Filament/Resources/ExpenseResource')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/Accounting')));
        $this->assertFalse(is_dir(app_path('Http/Controllers/Expenses')));
        $this->assertFalse(is_dir(resource_path('views/accounting')));
        $this->assertFalse(is_dir(app_path('Livewire/Accounting')));
        $this->assertFalse(is_dir(app_path('Jobs/Accounting')));
    }

    /**
     * AccountingExportTarget is a closed, single-case enum — confirms
     * no second/real export target (e.g. a live QuickBooks connection
     * distinct from the simulated one) was ever added.
     */
    public function test_accounting_export_target_enum_has_exactly_one_case(): void
    {
        $cases = \App\Enums\AccountingExportTarget::cases();

        $this->assertCount(1, $cases);
        $this->assertSame('quickbooks_online', $cases[0]->value);
    }
}
