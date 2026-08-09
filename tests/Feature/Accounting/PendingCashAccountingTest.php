<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Enums\PendingPaymentAllocationStatus;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PendingPaymentAllocation;
use App\Services\AccountingIntegrityService;
use App\Services\AccountingJournalPostingService;
use App\Services\EntitlementService;
use App\Services\ManualPaymentService;
use App\Services\OperatingChargebackService;
use App\Services\OperatingPaymentRefundService;
use App\Services\PaymentAllocationResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PendingCashAccountingTest — the required test matrix (items A-N) for
 * the Pending-Cash Accounting pass: cash received while a mixed-invoice
 * payment's fee/cost allocation is still ambiguous is posted
 * immediately to UnappliedOperatingFundsLiability, never left off the
 * books, and reclassified into revenue only once resolved.
 */
class PendingCashAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        [$cash, $feeRevenue, $costRevenue, $liability] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::LegalFeeRevenue)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::CostReimbursementRevenue)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Liability)->purpose(ChartOfAccountPurpose::UnappliedOperatingFundsLiability)->create(),
        ]);

        return [$firm, $cash, $feeRevenue, $costRevenue, $liability];
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

    private function makeAmbiguousPayment(Firm $firm, Client $client, Invoice $invoice, int $amountCents): Payment
    {
        return app(ManualPaymentService::class)->submit(
            $firm, $client, $amountCents, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );
    }

    private function receiptEntry(Firm $firm, Payment $payment): ?AccountingJournalEntry
    {
        return $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsReceived->value)
            ->first());
    }

    // A — ambiguous payment receipt: Dr Cash / Cr Unapplied Liability
    public function test_a_ambiguous_payment_posts_cash_received_against_unapplied_liability(): void
    {
        [$firm, $cash, , , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);

        $entry = $this->receiptEntry($firm, $payment);
        $this->assertNotNull($entry);
        $this->assertSame(40000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('debit_cents'));
        $this->assertSame(40000, $entry->postings->where('chart_of_account_id', $liability->id)->sum('credit_cents'));

        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->first());
        $this->assertSame($pending->id, $entry->pending_payment_allocation_id);
    }

    // B — no LegalFeeRevenue recognized yet
    public function test_b_ambiguous_payment_recognizes_no_legal_fee_revenue(): void
    {
        [$firm, , $feeRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);

        $credited = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)->get()
            ->flatMap->postings->where('chart_of_account_id', $feeRevenue->id)->sum('credit_cents'));

        $this->assertSame(0, $credited);
    }

    // C — no CostReimbursementRevenue recognized yet
    public function test_c_ambiguous_payment_recognizes_no_cost_reimbursement_revenue(): void
    {
        [$firm, , , $costRevenue] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);

        $credited = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)->get()
            ->flatMap->postings->where('chart_of_account_id', $costRevenue->id)->sum('credit_cents'));

        $this->assertSame(0, $credited);
    }

    // D — successful resolution, fee-only
    public function test_d_resolution_fee_only_clears_liability_and_posts_legal_fee_revenue_only(): void
    {
        [$firm, , $feeRevenue, $costRevenue, $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 40000, 0);

        $resolvedEntry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsResolved->value)
            ->first());

        $this->assertNotNull($resolvedEntry);
        $this->assertSame(40000, $resolvedEntry->postings->where('chart_of_account_id', $liability->id)->sum('debit_cents'));
        $this->assertSame(40000, $resolvedEntry->postings->where('chart_of_account_id', $feeRevenue->id)->sum('credit_cents'));
        $this->assertSame(0, $resolvedEntry->postings->where('chart_of_account_id', $costRevenue->id)->sum('credit_cents'));
        $this->assertCount(2, $resolvedEntry->postings, 'No cash leg — the cash was already posted at receipt.');
    }

    // E — successful resolution, cost-only
    public function test_e_resolution_cost_only_clears_liability_and_posts_cost_reimbursement_revenue_only(): void
    {
        [$firm, , $feeRevenue, $costRevenue, $liability] = $this->makeFirmWithAccounts();
        // fee=10000, cost=50000 — leaves enough cost-line headroom for a
        // full $40000 cost-only resolution below.
        [$client, $invoice] = $this->makeMixedInvoice($firm, 10000, 50000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 0, 40000);

        $resolvedEntry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsResolved->value)
            ->first());

        $this->assertNotNull($resolvedEntry);
        $this->assertSame(40000, $resolvedEntry->postings->where('chart_of_account_id', $liability->id)->sum('debit_cents'));
        $this->assertSame(0, $resolvedEntry->postings->where('chart_of_account_id', $feeRevenue->id)->sum('credit_cents'));
        $this->assertSame(40000, $resolvedEntry->postings->where('chart_of_account_id', $costRevenue->id)->sum('credit_cents'));
    }

    // F — successful mixed resolution
    public function test_f_resolution_mixed_split_clears_liability_and_posts_both_revenue_buckets(): void
    {
        [$firm, , $feeRevenue, $costRevenue, $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 30000, 10000);

        $resolvedEntry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsResolved->value)
            ->first());

        $this->assertNotNull($resolvedEntry);
        $this->assertSame(40000, $resolvedEntry->postings->where('chart_of_account_id', $liability->id)->sum('debit_cents'));
        $this->assertSame(30000, $resolvedEntry->postings->where('chart_of_account_id', $feeRevenue->id)->sum('credit_cents'));
        $this->assertSame(10000, $resolvedEntry->postings->where('chart_of_account_id', $costRevenue->id)->sum('credit_cents'));
        $this->assertCount(3, $resolvedEntry->postings);

        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(40000, $refreshedInvoice->amount_paid_cents);
    }

    // G — over-resolution rejected
    public function test_g_over_resolution_against_remaining_bucket_is_rejected(): void
    {
        [$firm, , , , $liability] = $this->makeFirmWithAccounts();
        // fee=50000, cost=10000 — a $40000 ambiguous payment proposing
        // 25000 fee / 15000 cost sums correctly to the pending amount,
        // but 15000 exceeds the invoice's own 10000 cost-line total.
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 10000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        try {
            app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 25000, 15000);
            $this->fail('Expected a RuntimeException for over-allocating the cost bucket.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceeds the invoice\'s current remaining cost-reimbursement balance', $e->getMessage());
        }

        $refreshedPending = $this->runWithFirmContext($firm, fn () => $pending->fresh());
        $this->assertTrue($refreshedPending->isPending(), 'A rejected over-allocation must never partially resolve the row.');

        $liabilityCredited = $this->runWithFirmContext($firm, fn () => (int) DB::table('accounting_postings')
            ->where('chart_of_account_id', $liability->id)
            ->sum('credit_cents'));
        $this->assertSame(40000, $liabilityCredited, 'Only the original receipt credit — no partial reclassification occurred.');
    }

    // H — duplicate resolution rejected
    public function test_h_duplicate_resolution_is_rejected_and_liability_is_not_cleared_twice(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 30000, 10000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been resolved/');

        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending->fresh(), $billingUser, 30000, 10000);
    }

    // I — refund before allocation
    public function test_i_full_refund_before_allocation_reverses_cash_receipt_and_cancels_pending_row(): void
    {
        [$firm, $cash, , , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        $refunded = app(OperatingPaymentRefundService::class)->refund($firm, $payment, 40000, 'Client requested a refund before allocation');

        $this->assertSame(PaymentStatus::Refunded, $refunded->status);

        $refreshedPending = $this->runWithFirmContext($firm, fn () => $pending->fresh());
        $this->assertTrue($refreshedPending->isCancelled());
        $this->assertNotNull($refreshedPending->cancelled_at);

        $original = $this->receiptEntry($firm, $payment);
        $reversal = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('reverses_journal_entry_id', $original->id)->first());

        $this->assertNotNull($reversal);
        $this->assertSame(40000, $reversal->postings->where('chart_of_account_id', $liability->id)->sum('debit_cents'));
        $this->assertSame(40000, $reversal->postings->where('chart_of_account_id', $cash->id)->sum('credit_cents'));

        // No revenue was ever recognized, and never will be.
        $revenueRows = $this->runWithFirmContext($firm, fn () => PaymentAllocation::query()->where('payment_id', $payment->id)->whereNotNull('revenue_purpose')->count());
        $this->assertSame(0, $revenueRows);
    }

    public function test_i_partial_refund_before_allocation_is_rejected(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/still pending/');

        app(OperatingPaymentRefundService::class)->refund($firm, $payment, 10000, 'Partial refund attempt while pending');
    }

    // J — chargeback before allocation
    public function test_j_chargeback_before_allocation_reverses_cash_receipt_and_cancels_pending_row(): void
    {
        [$firm, $cash, , , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        $reversed = app(OperatingChargebackService::class)->report($firm, $payment, 40000, 'Cardholder dispute before allocation');

        $this->assertSame(PaymentStatus::Reversed, $reversed->status);

        $refreshedPending = $this->runWithFirmContext($firm, fn () => $pending->fresh());
        $this->assertTrue($refreshedPending->isCancelled());

        $original = $this->receiptEntry($firm, $payment);
        $reversal = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('reverses_journal_entry_id', $original->id)->first());

        $this->assertNotNull($reversal);
        $this->assertSame(40000, $reversal->postings->where('chart_of_account_id', $liability->id)->sum('debit_cents'));
        $this->assertSame(40000, $reversal->postings->where('chart_of_account_id', $cash->id)->sum('credit_cents'));
    }

    // K — retry does not duplicate cash receipt
    public function test_k_a_resubmitted_ambiguous_payment_does_not_duplicate_the_cash_receipt_entry(): void
    {
        [$firm, $cash, , , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $idempotencyKey = (string) Str::uuid();

        app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            $idempotencyKey, invoice: $invoice,
        );
        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 40000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            $idempotencyKey, invoice: $invoice,
        );

        $entries = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsReceived->value)
            ->get());

        $this->assertCount(1, $entries);
        $this->assertSame(40000, $entries->first()->postings->where('chart_of_account_id', $cash->id)->sum('debit_cents'));
        $this->assertSame(40000, $entries->first()->postings->where('chart_of_account_id', $liability->id)->sum('credit_cents'));

        $pendingCount = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, $pendingCount);
    }

    // L — cross-firm isolation
    public function test_l_a_pending_allocations_cash_receipt_entry_is_not_visible_from_another_firms_context(): void
    {
        [$firmA] = $this->makeFirmWithAccounts();
        [$firmB] = $this->makeFirmWithAccounts();
        [$clientA, $invoiceA] = $this->makeMixedInvoice($firmA, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firmA, $clientA, $invoiceA, 40000);
        $entry = $this->receiptEntry($firmA, $payment);
        $this->assertNotNull($entry);

        $visibleFromFirmB = $this->runWithFirmContext($firmB, fn () => AccountingJournalEntry::query()->whereKey($entry->id)->exists());
        $this->assertFalse($visibleFromFirmB);

        $pendingVisibleFromFirmB = $this->runWithFirmContext($firmB, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->exists());
        $this->assertFalse($pendingVisibleFromFirmB);
    }

    // M — unauthorized allocation denial
    public function test_m_an_unauthorized_role_cannot_resolve_and_the_liability_stays_untouched(): void
    {
        [$firm, , , , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $receptionist = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Receptionist]);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        try {
            app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $receptionist, 30000, 10000);
            $this->fail('Expected a RuntimeException for an unauthorized role.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not authorized', $e->getMessage());
        }

        $refreshedPending = $this->runWithFirmContext($firm, fn () => $pending->fresh());
        $this->assertTrue($refreshedPending->isPending());

        $resolvedEntries = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::query()
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsResolved->value)
            ->count());
        $this->assertSame(0, $resolvedEntries);

        // The liability account itself was never touched beyond the
        // original receipt.
        $liabilityCreditTotal = $this->runWithFirmContext($firm, fn () => (int) DB::table('accounting_postings')
            ->where('chart_of_account_id', $liability->id)
            ->sum('credit_cents'));
        $this->assertSame(40000, $liabilityCreditTotal);
    }

    // N — AccountingIntegrityService drift detection
    public function test_n_a_clean_pending_cash_scenario_reports_no_new_findings(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());
        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 30000, 10000);

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->isClean(), 'Expected no findings, got: '.$report->findings->pluck('type')->implode(', '));
    }

    public function test_n_detects_a_pending_allocation_with_no_cash_receipt_entry(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create([
            'firm_id' => $firm->id, 'client_id' => $client->id, 'invoice_id' => $invoice->id,
            'amount_cents' => 40000, 'status' => PaymentStatus::Succeeded,
            'payment_classification' => PaymentClassification::OperatingPayment,
        ]));
        // Bypasses ManualPaymentService entirely — simulates historical
        // drift (a pending row created before this pass's own
        // recordUnappliedFundsReceived() wiring existed), never
        // constructible through the real service layer today.
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::factory()->forFirm($firm)->create([
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => 40000,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'pending_allocation_missing_cash_receipt_entry');
        $this->assertNotNull($finding);
        $this->assertSame($pending->id, $finding->subjectId);
    }

    public function test_n_detects_revenue_recognized_while_still_pending(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);

        // Simulates a hypothetical bug elsewhere that recognized
        // revenue before resolution — never constructible through
        // PaymentAllocationResolutionService/ManualPaymentService
        // themselves, which is exactly why this check exists as a
        // safety net.
        $this->runWithFirmContext($firm, fn () => PaymentAllocation::create([
            'firm_id' => $firm->id, 'payment_id' => $payment->id, 'invoice_id' => $invoice->id,
            'amount_cents' => 40000, 'revenue_purpose' => ChartOfAccountPurpose::LegalFeeRevenue->value,
            'created_at' => now(),
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'revenue_recognized_while_pending');
        $this->assertNotNull($finding);
    }

    public function test_n_detects_a_resolved_allocation_whose_liability_was_never_cleared(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());

        // Simulates drift: the row was marked Resolved (e.g. a direct
        // DB fixup) without the corresponding UnappliedFundsResolved
        // journal entry ever being posted — never possible through
        // PaymentAllocationResolutionService itself.
        $this->runWithFirmContext($firm, fn () => $pending->update([
            'status' => PendingPaymentAllocationStatus::Resolved,
            'resolved_at' => now(),
            'resolved_fee_cents' => 40000,
            'resolved_cost_cents' => 0,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'resolved_pending_allocation_liability_not_cleared');
        $this->assertNotNull($finding);
        $this->assertSame($pending->id, $finding->subjectId);
    }

    public function test_n_detects_a_liability_amount_mismatch(): void
    {
        [$firm, $cash, , , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);

        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create([
            'firm_id' => $firm->id, 'client_id' => $client->id, 'invoice_id' => $invoice->id,
            'amount_cents' => 40000, 'status' => PaymentStatus::Succeeded,
            'payment_classification' => PaymentClassification::OperatingPayment,
        ]));
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::factory()->forFirm($firm)->create([
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_cents' => 40000,
        ]));

        // Posts a receipt entry for the WRONG amount (30000 instead of
        // the pending row's 40000) directly via the posting service —
        // bypasses recordUnappliedFundsReceived()'s own guarantee that
        // the two always match, simulating drift.
        $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm,
            AccountingJournalSourceType::UnappliedFundsReceived,
            'Drift-simulated mismatched receipt',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 30000, 'credit_cents' => 0],
                ['chart_of_account_id' => $liability->id, 'debit_cents' => 0, 'credit_cents' => 30000],
            ],
            ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'pending_payment_allocation_id' => $pending->id],
        ));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'unapplied_liability_amount_mismatch');
        $this->assertNotNull($finding);
        $this->assertSame($pending->id, $finding->subjectId);
    }

    public function test_n_detects_a_liability_cleared_more_than_once(): void
    {
        [$firm, , $feeRevenue, , $liability] = $this->makeFirmWithAccounts();
        [$client, $invoice] = $this->makeMixedInvoice($firm, 50000, 30000);
        $billingUser = $this->billingUser($firm);

        $payment = $this->makeAmbiguousPayment($firm, $client, $invoice, 40000);
        $pending = $this->runWithFirmContext($firm, fn () => PendingPaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail());
        app(PaymentAllocationResolutionService::class)->resolve($firm, $pending, $billingUser, 40000, 0);

        // Simulates drift: a SECOND clearing entry posted directly,
        // bypassing PaymentAllocationResolutionService's own
        // already-resolved guard.
        $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm,
            AccountingJournalSourceType::UnappliedFundsResolved,
            'Drift-simulated duplicate clearing',
            now(),
            [
                ['chart_of_account_id' => $liability->id, 'debit_cents' => 40000, 'credit_cents' => 0],
                ['chart_of_account_id' => $feeRevenue->id, 'debit_cents' => 0, 'credit_cents' => 40000],
            ],
            ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'pending_payment_allocation_id' => $pending->id],
        ));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'unapplied_liability_cleared_multiple_times');
        $this->assertNotNull($finding);
    }
}
