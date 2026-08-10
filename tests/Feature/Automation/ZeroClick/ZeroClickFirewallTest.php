<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\AutomationActionType;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\WorkflowPreviewService;
use Tests\TestCase;

/**
 * ZeroClickFirewallTest — Zero-Click Core Workflow Automation, test
 * matrix T/U/V/AG. Proves the STRUCTURAL guarantee (mirrors
 * AutomationTrustAccountingFirewallTest's own shape, extended to
 * Conflict): no AutomationActionType case names a Trust, Accounting,
 * or Conflict-clearance mutation, and — the source-level backstop — no
 * registered Automation Action handler class file references any of
 * the forbidden canonical service methods at all, so a protected
 * action can never be reached even through a future config change to
 * an already-registered handler.
 */
class ZeroClickFirewallTest extends TestCase
{
    private const PROHIBITED_OPERATIONS = [
        'create_trust_ledger_entry',
        'create_accounting_journal_entry',
        'create_accounting_posting',
        'approve_trust_request',
        'resolve_payment_allocation',
        'issue_refund',
        'create_chargeback',
        'write_off_invoice',
        'reopen_accounting_period',
        'resolve_conflict_check',
        'clear_conflict',
        'waive_conflict_review',
    ];

    /**
     * Forbidden canonical call sites — a source-level grep, not a
     * runtime check, so this catches a future handler edit that adds a
     * forbidden call without ever needing to configure a rule to
     * exercise it.
     */
    private const FORBIDDEN_CALL_SITES = [
        'TrustLedgerEntry::create',
        'TrustDepositService',
        'TrustTransferRequestService',
        'TrustRefundRequestService',
        'TrustHighRiskAdjustmentService',
        'TrustLedgerEntryReversalService',
        'AccountingJournalEntry::create',
        'AccountingJournalPostingService',
        'AccountingJournalReversalService',
        'InvoiceWriteOff::create',
        'InvoiceWriteOffService',
        'PaymentAllocationResolutionService',
        'OperatingPaymentRefundService',
        'OperatingChargebackService',
        'ConflictCheckService::resolveResult',
        'ConflictCheckResult::create',
    ];

    public function test_no_registered_action_type_names_a_trust_accounting_or_conflict_mutation(): void
    {
        foreach (AutomationActionType::cases() as $case) {
            $this->assertNotContains($case->value, self::PROHIBITED_OPERATIONS);
        }
    }

    public function test_no_registered_action_handler_file_references_a_forbidden_canonical_call_site(): void
    {
        $registry = new AutomationActionHandlerRegistry;
        $reflection = new \ReflectionClass($registry);
        $map = $reflection->getConstant('MAP');

        $this->assertNotFalse($map, 'AutomationActionHandlerRegistry::MAP must exist.');

        foreach ($map as $handlerClass) {
            $handlerReflection = new \ReflectionClass($handlerClass);
            $source = file_get_contents($handlerReflection->getFileName());

            $this->assertNotFalse($source, "Could not read source for {$handlerClass}.");

            foreach (self::FORBIDDEN_CALL_SITES as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$handlerClass} must never reference {$forbidden} — Trust/Accounting/Conflict mutations are structurally forbidden to every Automation Action."
                );
            }
        }
    }

    public function test_workflow_preview_service_never_references_a_domain_mutation_method(): void
    {
        $reflection = new \ReflectionClass(WorkflowPreviewService::class);
        $source = file_get_contents($reflection->getFileName());

        foreach (['::create(', '::update(', '->save(', '->delete('] as $mutatingCall) {
            $this->assertStringNotContainsString($mutatingCall, $source, 'WorkflowPreviewService must remain read-only.');
        }
    }
}
