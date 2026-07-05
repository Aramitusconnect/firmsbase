<?php

namespace Tests\Feature\Accounting\TrustProtection;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required: trust/IOLTA payments are never exported, and Phase 12 must
 * never touch, debit, credit, reserve, release, or simulate trust/IOLTA
 * funds anywhere in its own service code (project rule / correction
 * #7). The functional proof that a TrustIoltaPayment/BlockedPayment
 * row is never selected lives in
 * AccountingExportLineBuilderServiceTest; this file is the static,
 * grep-based backstop confirming no Phase 12 service references a
 * trust ledger/account/transaction/reservation concept, platform
 * subscription payment, AI charge, or sales commission at all —
 * mirrors Phase 11's Phase11NoRealProviderOrFilingTest style exactly.
 */
class TrustIoltaNeverExportedTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'TrustIolta', 'trust_iolta', 'IoltaLedger', 'trust_account', 'trust_ledger',
        'trust_transaction', 'trust_reservation', 'TrustAccountingService',
        'PlatformPayment', 'platform_subscription', 'AiCharge', 'ai_charge',
        'SalesCommission', 'sales_commission',
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

    public function test_no_phase_12_service_references_any_trust_or_platform_billing_concept(): void
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

    /**
     * The one explicitly allowed exception: AccountingExportLineBuilderService
     * MAY (and must) reference PaymentClassification — the EXISTING,
     * unmodified Phase 3 enum — specifically to EXCLUDE
     * TrustIoltaPayment/BlockedPayment rows, never to include them.
     */
    public function test_export_line_builder_only_ever_selects_operating_payment_classification(): void
    {
        $source = file_get_contents(app_path('Services/AccountingExportLineBuilderService.php'));

        $this->assertStringContainsString('PaymentClassification::OperatingPayment', $source);
        $this->assertStringNotContainsString('PaymentClassification::TrustIoltaPayment', $source);
        $this->assertStringNotContainsString('PaymentClassification::BlockedPayment', $source);
    }
}
