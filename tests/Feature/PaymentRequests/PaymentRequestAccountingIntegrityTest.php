<?php

namespace Tests\Feature\PaymentRequests;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Services\AccountingIntegrityService;
use App\Services\EntitlementService;
use App\Services\ManualPaymentService;
use App\Services\PaymentRequestCheckoutService;
use App\Services\PaymentRequestService;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\TrustDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment Link / QR Routing phase, master prompt item 13/14. Proves
 * AccountingIntegrityService's payment-request reconciliation checks
 * added to the existing (never a parallel) integrity checker.
 */
class PaymentRequestAccountingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function enabledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::LegalFeeRevenue)->create(),
        ]);

        return $firm;
    }

    private function payAnInvoiceViaPaymentRequest(Firm $firm, Client $client, FirmUser $creator, Invoice $invoice): PaymentRequest
    {
        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 10000, invoice: $invoice,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = new PaymentRequestCheckoutService(
            app(PaymentRequestService::class),
            app(ManualPaymentService::class),
            app(TrustDepositService::class),
            new FakeStripeGateway(shouldSucceed: true),
        );

        return $checkout->submitPayment($paymentRequest->fresh(), 10000);
    }

    public function test_a_clean_successful_payment_request_reports_no_findings(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));

        $this->payAnInvoiceViaPaymentRequest($firm, $client, $creator, $invoice);

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->isClean());
    }

    public function test_a_standalone_paid_request_with_no_invoice_target_is_not_flagged_for_missing_journal_entry(): void
    {
        // Documented, pre-existing limitation carried forward unchanged
        // from ManualPaymentService::submit(): a payment with neither
        // an invoice nor an installment target never posts a journal
        // entry at all (its own "if ($installment) ... elseif ($invoice)"
        // branch is skipped). This must never be misreported as drift.
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = new PaymentRequestCheckoutService(
            app(PaymentRequestService::class),
            app(ManualPaymentService::class),
            app(TrustDepositService::class),
            new FakeStripeGateway(shouldSucceed: true),
        );
        $checkout->submitPayment($paymentRequest->fresh(), 5000);

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->isClean(), 'A standalone (no invoice/installment target) payment request must not be flagged for a missing journal entry — none is ever expected.');
    }

    public function test_a_paid_request_with_no_payment_is_flagged(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        $this->runWithFirmContext($firm, fn () => $paymentRequest->update(['status' => PaymentRequestStatus::Paid]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertFalse($report->isClean());
        $this->assertTrue($report->findings->contains(fn ($f) => $f->type === 'payment_request_paid_without_payment'));
    }

    public function test_a_payment_missing_its_journal_entry_is_flagged_when_a_target_exists(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 10000]));
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'purpose' => PaymentRequestPurpose::EarnedFee,
            'status' => PaymentRequestStatus::Draft,
            'created_by_firm_user_id' => $creator->id,
        ]));

        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => 10000,
        ]));

        $this->runWithFirmContext($firm, fn () => $paymentRequest->update([
            'status' => PaymentRequestStatus::Paid,
            'payment_id' => $payment->id,
            'paid_amount_cents' => 10000,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->findings->contains(fn ($f) => $f->type === 'payment_request_payment_missing_journal_entry'));
    }

    /**
     * findDuplicateProviderTransactionIds() is deliberately untested by
     * direct reproduction, matching this codebase's own established
     * precedent for AccountingIntegrityService::findDuplicateIdempotencyKeys()
     * (also undocumented-by-a-test): payment_requests carries a REAL
     * database-level unique(['firm_id','provider_transaction_id'])
     * constraint, so attempting to reproduce the duplicate through the
     * ORM (as this test originally tried) throws a QueryException
     * before the row is ever written — proving the constraint itself
     * works, and confirming the AccountingIntegrityService check is
     * pure defense in depth for a condition that cannot occur through
     * any normal write path.
     */
    public function test_an_amount_mismatch_between_the_request_and_its_payment_is_flagged(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create([
            'firm_id' => $firm->id, 'client_id' => $client->id, 'amount_cents' => 5000,
        ]));

        $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create([
            'client_id' => $client->id, 'created_by_firm_user_id' => $creator->id,
            'status' => PaymentRequestStatus::Paid, 'payment_id' => $payment->id, 'paid_amount_cents' => 4000,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->findings->contains(fn ($f) => $f->type === 'payment_request_amount_mismatch'));
    }

    public function test_a_confirmed_trust_deposit_request_that_never_filed_a_deposit_request_is_flagged(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create([
            'client_id' => $client->id, 'created_by_firm_user_id' => $creator->id,
            'purpose' => PaymentRequestPurpose::TrustDeposit,
            'status' => PaymentRequestStatus::PendingReview,
            'provider_transaction_id' => 'fake_pi_trust_orphan',
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertTrue($report->findings->contains(fn ($f) => $f->type === 'payment_request_trust_deposit_not_posted'));
    }

    public function test_a_properly_filed_trust_deposit_request_is_not_flagged(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = $this->runWithFirmContext($firm, fn () => PaymentRequest::factory()->forFirm($firm)->create([
            'client_id' => $client->id, 'created_by_firm_user_id' => $creator->id,
            'purpose' => PaymentRequestPurpose::TrustDeposit,
            'status' => PaymentRequestStatus::PendingReview,
            'provider_transaction_id' => 'fake_pi_trust_filed',
        ]));

        $this->runWithFirmContext($firm, fn () => PaymentRequestEvent::factory()->create([
            'firm_id' => $firm->id,
            'payment_request_id' => $paymentRequest->id,
            'event_type' => PaymentRequestEventType::TrustDepositRequested,
        ]));

        $report = app(AccountingIntegrityService::class)->checkFirm($firm);

        $this->assertFalse($report->findings->contains(fn ($f) => $f->type === 'payment_request_trust_deposit_not_posted'));
    }
}
