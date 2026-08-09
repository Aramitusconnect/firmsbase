<?php

namespace Tests\Feature\Accounting;

use App\Enums\ChartOfAccountType;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Services\InvoiceWriteOffService;
use App\Services\ManualPaymentService;
use App\Services\OperatingChargebackService;
use App\Services\OperatingPaymentRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — operating refunds/write-offs/chargebacks, distinct from
 * Trust refunds/chargebacks and Platform SaaS billing refunds.
 */
class OperatingRefundsWriteOffsChargebacksTest extends TestCase
{
    use RefreshDatabase;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();

        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        return [$firm, $cash, $revenue];
    }

    private function makePaidInvoice(Firm $firm, int $amountCents = 50000): array
    {
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'total_cents' => $amountCents,
        ]));

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, $amountCents, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $freshInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());

        return [$client, $freshInvoice, $payment];
    }

    public function test_a_full_refund_reverses_the_invoice_and_posts_a_compensating_journal_entry(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        [, $invoice, $payment] = $this->makePaidInvoice($firm, 50000);

        $refunded = app(OperatingPaymentRefundService::class)->refund($firm, $payment, 50000, 'Client dissatisfied');

        $this->assertSame(PaymentStatus::Refunded, $refunded->status);
        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(0, $refreshedInvoice->amount_paid_cents);
        $this->assertSame(InvoiceStatus::Sent, $refreshedInvoice->status);

        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('payment_id', $payment->id)->where('source_type', 'refund')->first());
        $this->assertNotNull($entry);
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('credit_cents'));
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $revenue->id)->sum('debit_cents'));
    }

    public function test_a_partial_refund_leaves_the_invoice_partially_paid(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [, $invoice, $payment] = $this->makePaidInvoice($firm, 50000);

        $refunded = app(OperatingPaymentRefundService::class)->refund($firm, $payment, 20000, 'Partial billing dispute');

        $this->assertSame(PaymentStatus::PartiallyRefunded, $refunded->status);
        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(30000, $refreshedInvoice->amount_paid_cents);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $refreshedInvoice->status);
    }

    public function test_total_refunds_cannot_exceed_the_original_payment(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [, , $payment] = $this->makePaidInvoice($firm, 50000);

        app(OperatingPaymentRefundService::class)->refund($firm, $payment, 30000, 'First partial');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot exceed/');

        $freshPayment = $this->runWithFirmContext($firm, fn () => $payment->fresh());
        app(OperatingPaymentRefundService::class)->refund($firm, $freshPayment, 30000, 'Second partial pushes over');
    }

    public function test_a_chargeback_must_exactly_match_the_remaining_payment_amount(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        [, , $payment] = $this->makePaidInvoice($firm, 50000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exactly match/');

        app(OperatingChargebackService::class)->report($firm, $payment, 25000, 'Disputed transaction');
    }

    public function test_a_chargeback_for_the_full_amount_reverses_the_payment_and_invoice(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        [, $invoice, $payment] = $this->makePaidInvoice($firm, 50000);

        $reversed = app(OperatingChargebackService::class)->report($firm, $payment, 50000, 'Cardholder dispute');

        $this->assertSame(PaymentStatus::Reversed, $reversed->status);
        $refreshedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(0, $refreshedInvoice->amount_paid_cents);

        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('payment_id', $payment->id)->where('source_type', 'chargeback')->first());
        $this->assertNotNull($entry);
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('credit_cents'));
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $revenue->id)->sum('debit_cents'));
    }

    public function test_writing_off_an_invoice_records_the_remaining_unpaid_balance_and_posts_no_journal_entry(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'total_cents' => 50000, 'amount_paid_cents' => 20000,
        ]));

        $writtenOff = app(InvoiceWriteOffService::class)->writeOff($firm, $invoice, 'Client went out of business');

        $this->assertSame(InvoiceStatus::WrittenOff, $writtenOff->status);

        $entryCount = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, $entryCount);

        $writeOffRow = $this->runWithFirmContext($firm, fn () => InvoiceWriteOff::where('invoice_id', $invoice->id)->first());
        $this->assertSame(30000, $writeOffRow->amount_cents);
    }

    public function test_a_fully_paid_invoice_cannot_be_written_off(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Paid)->create([
            'total_cents' => 50000, 'amount_paid_cents' => 50000,
        ]));

        $this->expectException(\RuntimeException::class);

        app(InvoiceWriteOffService::class)->writeOff($firm, $invoice, 'Should not be allowed');
    }
}
