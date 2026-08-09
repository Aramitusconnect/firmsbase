<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\PaymentApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase F — payment allocation splitting, an extension of the
 * canonical PaymentApplicationService (never a second service).
 * Proves the required invariants: no over-application, no duplicate
 * application, no cross-firm/cross-client leakage, auditable history.
 */
class PaymentApplicationServiceSplitTest extends TestCase
{
    use RefreshDatabase;

    private PaymentApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentApplicationService::class);
    }

    public function test_a_payment_can_be_split_across_two_invoices(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $invoiceB = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 15000]));

        $allocations = $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceA, 'amount_cents' => 10000],
            ['invoice' => $invoiceB, 'amount_cents' => 5000],
        ]));

        $this->assertCount(2, $allocations);
        $refreshedA = $this->runWithFirmContext($firm, fn () => $invoiceA->fresh());
        $refreshedB = $this->runWithFirmContext($firm, fn () => $invoiceB->fresh());
        $this->assertSame(InvoiceStatus::Paid, $refreshedA->status);
        $this->assertSame(5000, $refreshedB->amount_paid_cents);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $refreshedB->status);

        $storedCount = $this->runWithFirmContext($firm, fn () => PaymentAllocation::where('payment_id', $payment->id)->count());
        $this->assertSame(2, $storedCount);
    }

    public function test_allocations_cannot_exceed_the_payment_amount(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $invoiceB = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 5000]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/over-applied|exceed/');

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceA, 'amount_cents' => 3000],
            ['invoice' => $invoiceB, 'amount_cents' => 3000],
        ]));
    }

    /**
     * Accounting Integrity Hardening Pass, item 9: an under-allocated
     * split (sum(allocations) < payment amount) used to be silently
     * accepted, leaving the remainder with no defined destination.
     * Full allocation is now required — there is no such thing as a
     * partially-applied split payment.
     */
    public function test_allocations_must_exactly_equal_the_payment_amount_leaving_no_unapplied_residual(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 10000]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exactly equal|unapplied/');

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceA, 'amount_cents' => 6000],
        ]));
    }

    public function test_a_payment_cannot_be_split_allocated_twice(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $invoiceB = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 10000]));

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceA, 'amount_cents' => 10000],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been split-allocated/');

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceB, 'amount_cents' => 5000],
        ]));
    }

    public function test_a_payment_already_targeting_a_single_invoice_cannot_also_be_split(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $invoiceB = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 10000, 'invoice_id' => $invoiceA->id]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/single direct target/');

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceB, 'amount_cents' => 5000],
        ]));
    }

    public function test_cannot_allocate_to_an_invoice_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $foreignInvoice = $this->runWithFirmContext($firmB, fn () => Invoice::factory()->forClient($clientB)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firmA, fn () => Payment::factory()->forClient($clientA)->create(['amount_cents' => 10000]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/different firm/');

        $this->runWithFirmContext($firmA, fn () => $this->service->applySplit($payment, [
            ['invoice' => $foreignInvoice, 'amount_cents' => 5000],
        ]));
    }

    public function test_cannot_allocate_to_an_invoice_belonging_to_a_different_client(): void
    {
        $firm = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $clientB = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceForB = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($clientB)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($clientA)->create(['amount_cents' => 10000]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/different client/');

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceForB, 'amount_cents' => 5000],
        ]));
    }

    public function test_the_same_invoice_cannot_receive_two_allocations_in_one_split(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoiceA = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forClient($client)->create(['amount_cents' => 10000]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/same target/');

        $this->runWithFirmContext($firm, fn () => $this->service->applySplit($payment, [
            ['invoice' => $invoiceA, 'amount_cents' => 5000],
            ['invoice' => $invoiceA, 'amount_cents' => 5000],
        ]));
    }
}
