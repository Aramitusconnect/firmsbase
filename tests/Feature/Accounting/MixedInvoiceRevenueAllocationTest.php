<?php

namespace Tests\Feature\Accounting;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\PendingPaymentAllocationStatus;
use App\Exceptions\InvoiceRevenueAllocationExceedsRemainingBalanceException;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PendingPaymentAllocation;
use App\Services\AccountingIntegrityService;
use App\Services\EntitlementService;
use App\Services\ManualPaymentService;
use App\Services\PaymentAllocationResolutionService;
use App\Services\PaymentApplicationService;
use App\Services\PaymentRequestCheckoutService;
use App\Services\PaymentRequestService;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\TrustDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MixedInvoiceRevenueAllocationTest — the required test matrix (items
 * A-P) for the Mixed-Invoice Revenue Allocation pass. Covers every
 * scenario the master prompt's own item 12 lists: unambiguous full/
 * purpose-constrained payments, the governed PendingPaymentAllocation
 * review path for genuinely ambiguous payments, over-allocation
 * rejection, authorization, immutability, reconciliation, cross-firm
 * denial, and idempotency.
 */
class MixedInvoiceRevenueAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        [$cash, $feeRevenue, $costRevenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::LegalFeeRevenue)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::CostReimbursementRevenue)->create(),
        ]);

        return [$firm, $cash, $feeRevenue, $costRevenue];
    }

    /**
     * @return array{0: Client, 1: Invoice}
     */
    private function makeMixedInvoice(Firm $firm, int $feeCents, int $costCents): array
    {
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'subtotal_cents' => $feeCents + $costCents, 'total_cents' => $feeCents + $costCents,
        ]));
        $this->runWithFirmContext($firm, fn () => [
            InvoiceLine::factory()->forInvoice($invoice)->create(['line_type' => InvoiceLineType::FlatFee, 'description' => 'Flat fee', 'amount_cents' => $feeCents]),
            InvoiceLine::factory()->forInvoice($invoice)->create(['line_type' => InvoiceLineType::ReimbursableExpense, 'description' => 'Reimbursable filing cost', 'amount_cents' => $costCents]),
        ]);

        return [$client, $invoice];
    }

    private function billingUser(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
    }

    private function journalRevenueCredited(Firm $firm, Invoice $invoice, ChartOfAccount $account): int
    {
        return $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('invoice_id', $invoice->id)
            ->get()
            ->flatMap->postings
            ->where('chart_of_account_id', $account->id)
            ->sum('credit_cents'));
    }

    // A — mixed invoice full payment
    public function test_a_mixed_invoice_full_payment_splits_revenue_correctly(): void
    {
        [$firm, $cash, $feeRevenue, $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 80000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('invoice_id', $invoice->id)->first());
        $this->assertNotNull($entry);
        $this->assertSame(80000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('debit_cents'));
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $feeRevenue->id)->sum('credit_cents'));
        $this->assertSame(30000, $entry->postings->where('chart_of_account_id', $costRevenue->id)->sum('credit_cents'));

        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(80000, $refreshedInvoice->amount_paid_cents);
    }

    // B — fee-purpose partial payment
    public function test_b_fee_purpose_partial_payment_posts_only_legal_fee_revenue(): void
    {
        [$firm, , $feeRevenue, $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 30000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::EarnedFee,
        );

        $this->assertSame(30000, $this->journalRevenueCredited($firm, $invoice, $feeRevenue));
        $this->assertSame(0, $this->journalRevenueCredited($firm, $invoice, $costRevenue));
    }

    // C — cost-purpose partial payment
    public function test_c_cost_purpose_partial_payment_posts_only_cost_reimbursement_revenue(): void
    {
        [$firm, , $feeRevenue, $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 20000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::FilingCostReimbursement,
        );

        $this->assertSame(0, $this->journalRevenueCredited($firm, $invoice, $feeRevenue));
        $this->assertSame(20000, $this->journalRevenueCredited($firm, $invoice, $costRevenue));
    }

    // D — the master prompt's own worked multi-payment sequence, item 6
    public function test_d_multiple_purpose_specific_partial_payments_reconcile_exactly(): void
    {
        [$firm, , $feeRevenue, $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 20000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::FilingCostReimbursement,
        );
        $invoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $remaining = $this->runWithFirmContext($firm, fn () => app(PaymentApplicationService::class)->invoiceRevenueRemaining($invoice));
        $this->assertSame(50000, $remaining['fee_remaining_cents']);
        $this->assertSame(10000, $remaining['cost_remaining_cents']);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 30000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::EarnedFee,
        );
        $invoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $remaining = $this->runWithFirmContext($firm, fn () => app(PaymentApplicationService::class)->invoiceRevenueRemaining($invoice));
        $this->assertSame(20000, $remaining['fee_remaining_cents']);
        $this->assertSame(10000, $remaining['cost_remaining_cents']);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 20000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::EarnedFee,
        );
        $invoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        app(ManualPaymentService::class)->submit(
            $firm, $client, 10000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::FilingCostReimbursement,
        );

        $finalInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(0, $finalInvoice->total_cents - $finalInvoice->amount_paid_cents, 'Final invoice balance must be exactly zero.');
        $this->assertSame(50000, $this->journalRevenueCredited($firm, $finalInvoice, $feeRevenue));
        $this->assertSame(30000, $this->journalRevenueCredited($firm, $finalInvoice, $costRevenue));
    }

    // E — ambiguous manual partial payment
    public function test_e_ambiguous_manual_partial_payment_defers_to_pending_allocation(): void
    {
        [$firm, , $feeRevenue, $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $this->assertSame(PaymentStatus::Succeeded, $payment->status, 'Real money received — the payment itself still succeeds.');
        $this->assertSame(0, $this->journalRevenueCredited($firm, $invoice, $feeRevenue));
        $this->assertSame(0, $this->journalRevenueCredited($firm, $invoice, $costRevenue));

        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(0, $refreshedInvoice->amount_paid_cents);

        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->first());
        $this->assertNotNull($pending);
        $this->assertTrue($pending->isPending());
        $this->assertSame(40000, $pending->amount_cents);
    }

    // F — ambiguous "processor" (payment-request) partial payment
    public function test_f_ambiguous_payment_request_partial_payment_lands_in_pending_review_not_paid(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $creator = $this->billingUser($firm);

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forClient($client)->active()->create([
            'invoice_id' => $invoice->id, 'total_cents' => 80000, 'installment_count' => 2,
        ]));
        $installment = $this->runWithFirmContext($firm, fn () => PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create([
            'amount_cents' => 40000,
        ]));

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::PaymentPlanInstallment, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 40000, installment: $installment,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = new PaymentRequestCheckoutService(
            app(PaymentRequestService::class),
            app(ManualPaymentService::class),
            app(TrustDepositService::class),
            new FakeStripeGateway(shouldSucceed: true),
        );
        $result = $checkout->submitPayment($paymentRequest->fresh(), 40000);

        $this->assertSame(PaymentRequestStatus::PendingReview, $result->status);
        $this->assertNotNull($result->payment_id);

        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $result->payment_id)->first());
        $this->assertNotNull($pending);
        $this->assertTrue($pending->isPending());
    }

    // G — payment-plan installment with explicit allocation
    public function test_g_payment_plan_installment_with_explicit_purpose_resolves_and_posts(): void
    {
        [$firm, , $feeRevenue, $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forClient($client)->active()->create([
            'invoice_id' => $invoice->id, 'total_cents' => 80000, 'installment_count' => 2,
        ]));
        $installment = $this->runWithFirmContext($firm, fn () => PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create([
            'amount_cents' => 30000,
        ]));

        app(ManualPaymentService::class)->submit(
            $firm, $client, 30000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), installment: $installment, purposeHint: PaymentRequestPurpose::EarnedFee,
        );

        $refreshedInstallment = $this->runWithFirmContext($firm, fn () => $installment->fresh());
        $this->assertSame(30000, $refreshedInstallment->paid_amount_cents);
        $this->assertSame(30000, $this->journalRevenueCredited($firm, $invoice, $feeRevenue));
        $this->assertSame(0, $this->journalRevenueCredited($firm, $invoice, $costRevenue));
    }

    // H — payment-plan installment with ambiguous mixed allocation
    public function test_h_payment_plan_installment_with_ambiguous_allocation_defers(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forClient($client)->active()->create([
            'invoice_id' => $invoice->id, 'total_cents' => 80000, 'installment_count' => 2,
        ]));
        $installment = $this->runWithFirmContext($firm, fn () => PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create([
            'amount_cents' => 40000,
        ]));

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), installment: $installment,
        );

        $refreshedInstallment = $this->runWithFirmContext($firm, fn () => $installment->fresh());
        $this->assertSame(0, $refreshedInstallment->paid_amount_cents);

        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->first());
        $this->assertNotNull($pending);
        $this->assertSame($installment->id, $pending->payment_plan_installment_id);
        $this->assertSame($invoice->id, $pending->invoice_id, 'Denormalized from installment.paymentPlan.invoice_id.');
    }

    // I — over-allocation to fee bucket
    public function test_i_over_allocation_to_fee_bucket_is_rejected(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $this->expectException(InvoiceRevenueAllocationExceedsRemainingBalanceException::class);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 60000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::EarnedFee,
        );
    }

    // J — over-allocation to cost bucket
    public function test_j_over_allocation_to_cost_bucket_is_rejected(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $this->expectException(InvoiceRevenueAllocationExceedsRemainingBalanceException::class);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::FilingCostReimbursement,
        );
    }

    // K — duplicate allocation (resolving an already-resolved row)
    public function test_k_resolving_an_already_resolved_allocation_is_rejected(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 30000, 10000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been resolved/');

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending->fresh(), $billingUser, 30000, 10000);
    }

    // L — allocation correction/reversal (immutability)
    public function test_l_payment_allocation_rows_are_append_only(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());
        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 30000, 10000);

        $row = $this->runWithFirmContext($firm, fn () => PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->runWithFirmContext($firm, fn () => $row->update(['amount_cents' => 999]));
    }

    // M — journal/accounting reconciliation
    public function test_m_accounting_integrity_service_reports_no_findings_for_a_correctly_reconciled_mixed_invoice(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        app(ManualPaymentService::class)->submit(
            $firm, $client, 30000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice, purposeHint: PaymentRequestPurpose::FilingCostReimbursement,
        );
        $invoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 50000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);
        $this->assertTrue(
            $report->findings->whereIn('type', [
                'payment_allocation_total_mismatches_journal_posting',
                'mixed_invoice_fully_paid_with_no_cost_allocation',
                'unbalanced_journal_entry',
                'payment_over_allocated',
                'invoice_allocation_exceeds_amount_paid',
            ])->isEmpty(),
            'A correctly reconciled mixed invoice must report none of the reconciliation findings: '.$report->findings->pluck('type')->implode(', '),
        );

        // The second payment ($500 remaining fee + $0 remaining cost = a
        // fee-only remainder) exactly finishes the invoice unambiguously
        // — proving both branches of the reconciliation stay clean.
        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame($refreshedInvoice->total_cents, $refreshedInvoice->amount_paid_cents);
    }

    // N — cross-firm denial
    public function test_n_resolving_a_pending_allocation_from_a_different_firm_is_denied(): void
    {
        [$firmA] = $this->makeFirmWithAccounts();
        [$firmB] = $this->makeFirmWithAccounts();
        [$clientA, $invoiceA] = $this->makeMixedInvoice($firmA, 50000, 30000);
        $billingUserB = $this->billingUser($firmB);

        $payment = app(ManualPaymentService::class)->submit(
            $firmA, $clientA, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoiceA,
        );
        $pending = $this->runWithFirmContext($firmA, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not belong to this firm/');

        app(PaymentAllocationResolutionService::class)->resolve($firmB, $pending, $billingUserB, 30000, 10000);
    }

    // O — authorization
    public function test_o_only_an_authorized_role_can_resolve_a_pending_allocation(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $receptionist = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Receptionist]);
        $billingUser = $this->billingUser($firm);

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        try {
            app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $receptionist, 30000, 10000);
            $this->fail('Expected a RuntimeException for an unauthorized role.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not authorized', $e->getMessage());
        }

        $resolved = app(PaymentAllocationResolutionService::class)->resolve($firm, $pending->fresh(), $billingUser, 30000, 10000);
        $this->assertTrue($resolved->status === PendingPaymentAllocationStatus::Resolved);
        $this->assertSame($billingUser->id, $resolved->resolved_by_firm_user_id);
    }

    // P — idempotency
    public function test_p_a_resubmitted_ambiguous_payment_does_not_create_a_second_pending_allocation(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $idempotencyKey = (string) Str::uuid();

        app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            $idempotencyKey, invoice: $invoice,
        );
        app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            $idempotencyKey, invoice: $invoice,
        );

        $paymentCount = $this->runWithFirmContext($firm, fn () => Payment::query()->where('idempotency_key', $idempotencyKey)->count());
        $pendingCount = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('invoice_id', $invoice->id)->count());

        $this->assertSame(1, $paymentCount);
        $this->assertSame(1, $pendingCount);
    }
}
