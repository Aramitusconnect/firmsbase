<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentReversalType;
use App\Enums\PaymentStatus;
use App\Enums\TrustTransferRequestStatus;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use App\Models\TrustTransferRequest;
use App\Services\AccountingIntegrityService;
use App\Services\EntitlementService;
use App\Services\ManualPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Accounting Integrity Hardening Pass, item 10. AccountingIntegrityService
 * is read-only — every test below verifies it detects a condition
 * WITHOUT the service itself ever mutating anything (proven implicitly:
 * no test asserts a state change caused by calling checkFirm()).
 */
class AccountingIntegrityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function enabledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    public function test_a_clean_firm_reports_no_findings(): void
    {
        $firm = $this->enabledFirm();
        $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::LegalFeeRevenue)->create(),
        ]);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));

        app(ManualPaymentService::class)->submit(
            $firm, $client, 10000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->isClean());
    }

    public function test_a_firm_with_accounting_disabled_reports_no_findings_even_with_unjournaled_payments(): void
    {
        $firm = Firm::factory()->create();
        // Accounting never enabled for this firm — no journal entry is
        // ever expected, so an unjournaled payment is NOT a finding.
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));

        app(ManualPaymentService::class)->submit(
            $firm, $client, 10000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->isClean());
    }

    /**
     * The historical-drift proof: a Payment created WITHOUT going
     * through ManualPaymentService (simulating a row that predates the
     * Accounting Integrity Hardening Pass, when journal failure was
     * still silent) in a firm that HAS accounting enabled.
     */
    public function test_detects_an_accepted_payment_with_no_journal_entry(): void
    {
        $firm = $this->enabledFirm();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));

        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create([
            'invoice_id' => $invoice->id,
            'amount_cents' => 10000,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertFalse($report->isClean());
        $finding = $report->findings->firstWhere('type', 'payment_missing_journal_entry');
        $this->assertNotNull($finding);
        $this->assertSame($payment->id, $finding->subjectId);
    }

    public function test_detects_an_applied_trust_transfer_with_no_journal_entry(): void
    {
        $firm = $this->enabledFirm();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $request = $this->runWithFirmContext($firm, fn () => TrustTransferRequest::factory()->create([
            'firm_id' => $firm->id,
            'invoice_id' => $invoice->id,
            'status' => TrustTransferRequestStatus::Applied,
            'requested_by_firm_user_id' => $requester->id,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'trust_transfer_missing_journal_entry');
        $this->assertNotNull($finding);
        $this->assertSame($request->id, $finding->subjectId);
    }

    public function test_detects_a_reversal_with_no_compensating_journal_entry(): void
    {
        $firm = $this->enabledFirm();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create([
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Refunded,
        ]));

        $reversal = $this->runWithFirmContext($firm, fn () => PaymentReversal::factory()->forFirm($firm)->create([
            'payment_id' => $payment->id,
            'reversal_type' => PaymentReversalType::Refund,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'reversal_missing_compensating_entry');
        $this->assertNotNull($finding);
        $this->assertSame($reversal->id, $finding->subjectId);
    }

    public function test_detects_a_payment_over_allocated_beyond_its_own_amount(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $invoiceB = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 5000]));

        // Bypasses PaymentApplicationService::applySplit()'s own strict
        // invariant to simulate historical/corrupted data.
        $this->runWithFirmContext($firm, function () use ($firm, $payment, $invoiceA, $invoiceB) {
            PaymentAllocation::create(['firm_id' => $firm->id, 'payment_id' => $payment->id, 'invoice_id' => $invoiceA->id, 'amount_cents' => 4000, 'created_at' => now()]);
            PaymentAllocation::create(['firm_id' => $firm->id, 'payment_id' => $payment->id, 'invoice_id' => $invoiceB->id, 'amount_cents' => 4000, 'created_at' => now()]);
        });

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'payment_over_allocated');
        $this->assertNotNull($finding);
        $this->assertSame($payment->id, $finding->subjectId);
    }

    public function test_detects_a_journal_entry_created_after_its_period_was_closed_but_dated_inside_it(): void
    {
        $firm = Firm::factory()->create();
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $period = $this->runWithFirmContext($firm, fn () => AccountingPeriod::create([
            'firm_id' => $firm->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'status' => AccountingPeriodStatus::Closed,
            'closed_by_firm_user_id' => $closer->id,
            'closed_at' => now()->subHour(),
        ]));

        // Bypasses AccountingJournalPostingService::post()'s own
        // write-time closed-period guard, to simulate a hypothetical
        // guard bypass/bug — created_at defaults to "now", which is
        // AFTER the period's closed_at set above.
        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::create([
            'firm_id' => $firm->id,
            'entry_date' => now()->startOfMonth()->addDays(5),
            'description' => 'Simulated bypass',
            'source_type' => 'adjustment',
        ]));
        $this->runWithFirmContext($firm, function () use ($firm, $entry, $cash, $revenue) {
            $entry->postings()->create(['firm_id' => $firm->id, 'chart_of_account_id' => $cash->id, 'debit_cents' => 1000, 'credit_cents' => 0]);
            $entry->postings()->create(['firm_id' => $firm->id, 'chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 1000]);
        });

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $finding = $report->findings->firstWhere('type', 'closed_period_violation');
        $this->assertNotNull($finding);
        $this->assertSame($entry->id, $finding->subjectId);
    }
}
