<?php

namespace Tests\Feature\Trust\Transfers;

use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentClassification;
use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustLedgerStatus;
use App\Enums\TrustTransferRequestStatus;
use App\Exceptions\TrustLedgerNotActiveException;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustTransferRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * The trust-to-invoice transfer workflow. apply() must never bypass
 * existing invoice payment rules and must never request
 * PaymentClassification::TrustIoltaPayment (only ever OperatingPayment).
 */
class TrustTransferRequestServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustTransferRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustTransferRequestService::class);
    }

    private function setupFundedLedgerAndInvoice(int $depositAmount = 20000): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $invoice = Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'matter_id' => $matter->id,
            'subtotal_cents' => $depositAmount,
            'total_cents' => $depositAmount,
        ]);

        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $deposits = app(TrustDepositService::class);
        $depositRequest = $deposits->requestDeposit($firm, $ledger, $requester, $depositAmount, $matter);
        $approved = $deposits->approveDeposit($firm, $depositRequest, $approver);
        $deposits->post($firm, $ledger, $approved, $matter);

        return [$firm, $ledger, $matter, $invoice, $requester, $approver];
    }

    public function test_full_transfer_lifecycle_applies_a_payment_to_the_invoice(): void
    {
        [$firm, $ledger, $matter, $invoice, $requester, $approver] = $this->setupFundedLedgerAndInvoice(20000);

        $request = $this->service->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 15000);
        $this->service->approveTransfer($firm, $request, $approver);
        $payment = $this->service->apply($firm, $request->fresh(), $approver);

        $this->assertSame(PaymentClassification::OperatingPayment, $payment->payment_classification);
        $this->assertSame(TrustTransferRequestStatus::Applied, $request->fresh()->status);
        $reFetchedInvoice = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(InvoiceStatus::PartiallyPaid, $reFetchedInvoice->status);
        $this->assertSame(15000, $reFetchedInvoice->amount_paid_cents);
        $this->assertSame(5000, $ledger->balance->fresh()->balance_cents);
        $this->assertDatabaseHas('trust_ledger_entries', [
            'trust_transfer_request_id' => $request->id,
            'entry_type' => TrustLedgerEntryType::WithdrawalToInvoice->value,
            'amount_cents' => -15000,
        ]);
    }

    public function test_transfer_cannot_apply_more_than_the_available_ledger_balance(): void
    {
        [$firm, $ledger, $matter, $invoice, $requester, $approver] = $this->setupFundedLedgerAndInvoice(5000);

        $request = $this->service->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 15000);
        $this->service->approveTransfer($firm, $request, $approver);

        $this->expectException(\RuntimeException::class);
        $this->service->apply($firm, $request->fresh(), $approver);
    }

    public function test_transfer_cannot_apply_to_an_invoice_that_is_still_a_draft(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $invoice = Invoice::factory()->forClient($client)->status(InvoiceStatus::Draft)->create([
            'matter_id' => $matter->id,
            'subtotal_cents' => 10000,
            'total_cents' => 10000,
        ]);
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $deposits = app(TrustDepositService::class);
        $depositRequest = $deposits->requestDeposit($firm, $ledger, $requester, 20000, $matter);
        $approved = $deposits->approveDeposit($firm, $depositRequest, $approver);
        $deposits->post($firm, $ledger, $approved, $matter);

        $request = $this->service->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 10000);
        $this->service->approveTransfer($firm, $request, $approver);

        $this->expectException(\RuntimeException::class);
        $this->service->apply($firm, $request->fresh(), $approver);
    }

    public function test_transfer_request_cannot_be_created_for_a_matter_of_a_different_client(): void
    {
        [$firm, $ledger, $matter, $invoice, $user] = $this->setupFundedLedgerAndInvoice(20000);
        $otherClient = Client::factory()->forFirm($firm)->create();
        $otherMatter = Matter::factory()->forClient($otherClient)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->requestTransfer($firm, $ledger, $otherMatter, $invoice, $user, 5000);
    }

    public function test_billing_staff_cannot_approve_a_transfer(): void
    {
        [$firm, $ledger, $matter, $invoice, $user] = $this->setupFundedLedgerAndInvoice(20000);
        $billingStaff = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $request = $this->service->requestTransfer($firm, $ledger, $matter, $invoice, $billingStaff, 5000);

        $this->expectException(\RuntimeException::class);
        $this->service->approveTransfer($firm, $request, $billingStaff);
    }

    public function test_a_firm_owner_cannot_approve_their_own_transfer_request(): void
    {
        [$firm, $ledger, $matter, $invoice, $requester] = $this->setupFundedLedgerAndInvoice(20000);

        $request = $this->service->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 5000);

        $this->expectException(\RuntimeException::class);
        $this->service->approveTransfer($firm, $request, $requester);
    }

    /**
     * Trust & Accounting Integrity Hardening, Mission 1.1: an Approved
     * transfer request must not be applicable once its ledger has been
     * frozen or closed after approval — the exact race the prior audit
     * flagged (an approved-but-unapplied request could otherwise post
     * against a non-Active ledger).
     */
    public function test_an_approved_transfer_cannot_be_applied_to_a_frozen_ledger(): void
    {
        [$firm, $ledger, $matter, $invoice, $requester, $approver] = $this->setupFundedLedgerAndInvoice(20000);
        $request = $this->service->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 15000);
        $this->service->approveTransfer($firm, $request, $approver);

        app(TrustLedgerService::class)->freeze($firm, $ledger->fresh());

        try {
            $this->service->apply($firm, $request->fresh(), $approver);
            $this->fail('Expected a TrustLedgerNotActiveException.');
        } catch (TrustLedgerNotActiveException $e) {
            $this->assertSame(TrustLedgerStatus::Frozen, $e->status);
        }

        $this->assertSame(TrustTransferRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(20000, $ledger->balance->fresh()->balance_cents);
    }

    /**
     * requestTransfer()/approveTransfer() intentionally do not check
     * ledger status (they move no money) — only apply() does, since
     * that is the step that actually posts a TrustLedgerEntry. This
     * test proves that gate holds even when a request was validly
     * approved before the ledger closed: a never-funded (zero-balance)
     * ledger can close immediately, a transfer can still be requested
     * and approved against it (no funds move yet), but apply() must
     * refuse.
     */
    public function test_an_approved_transfer_cannot_be_applied_to_a_closed_ledger(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        app(TrustLedgerService::class)->close($firm, $ledger->fresh());

        $matter = Matter::factory()->forClient($client)->create();
        $invoice = Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'matter_id' => $matter->id,
            'subtotal_cents' => 1000,
            'total_cents' => 1000,
        ]);
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $request = $this->service->requestTransfer($firm, $ledger->fresh(), $matter, $invoice, $requester, 1000);
        $this->service->approveTransfer($firm, $request, $approver);

        $this->expectException(TrustLedgerNotActiveException::class);
        $this->service->apply($firm, $request->fresh(), $approver);
    }
}
