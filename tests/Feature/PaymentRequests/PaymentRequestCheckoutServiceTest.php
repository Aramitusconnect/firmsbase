<?php

namespace Tests\Feature\PaymentRequests;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRequestEvent;
use App\Models\TrustApprovalEvent;
use App\Services\EntitlementService;
use App\Services\ManualPaymentService;
use App\Services\PaymentRequestCheckoutService;
use App\Services\PaymentRequestService;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class PaymentRequestCheckoutServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

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

    private function checkoutServiceWithGateway(StripeGateway $gateway): PaymentRequestCheckoutService
    {
        return new PaymentRequestCheckoutService(
            app(PaymentRequestService::class),
            app(ManualPaymentService::class),
            app(TrustDepositService::class),
            $gateway,
        );
    }

    public function test_a_successful_operating_payment_reaches_paid_and_posts_a_journal_entry(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create(['total_cents' => 20000]));

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 20000, invoice: $invoice,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));
        $result = $checkout->submitPayment($paymentRequest->fresh(), 20000);

        $this->assertSame(PaymentRequestStatus::Paid, $result->status);
        $this->assertSame(20000, $result->paid_amount_cents);
        $this->assertNotNull($result->payment_id);

        $payment = $this->runWithFirmContext($firm, fn () => $result->payment()->first());
        $this->assertSame(PaymentClassification::OperatingPayment, $payment->payment_classification);
        $this->assertSame(ManualPaymentMethod::PaymentLink, $payment->payment_method);

        $journalEntry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::query()->where('payment_id', $result->payment_id)->first());
        $this->assertNotNull($journalEntry, 'A successful entry-channel operating payment applied to an invoice must post a journal entry.');
    }

    public function test_a_declined_provider_payment_marks_the_request_failed_and_creates_no_payment(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: false, failureReason: 'card_declined'));
        $result = $checkout->submitPayment($paymentRequest->fresh(), 5000);

        $this->assertSame(PaymentRequestStatus::Failed, $result->status);
        $this->assertSame('card_declined', $result->failure_reason);
        $this->assertNull($result->payment_id);
    }

    public function test_a_trust_deposit_purpose_never_posts_directly_and_requires_dual_control_approval(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::TrustDeposit, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 30000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));
        $result = $checkout->submitPayment($paymentRequest->fresh(), 30000);

        $this->assertSame(PaymentRequestStatus::PendingReview, $result->status, 'A confirmed Trust deposit must never reach Paid directly — it always awaits dual-control approval.');
        $this->assertNull($result->payment_id, 'A Trust deposit never creates a Payment/journal entry through the entry channel.');

        $depositRequested = $this->runWithFirmContext($firm, fn () => TrustApprovalEvent::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('amount_cents', 30000)
            ->first());
        $this->assertNotNull($depositRequested, 'A confirmed Trust deposit payment request must file a real TrustDepositService deposit request.');

        $balance = $this->runWithFirmContext($firm, fn () => $ledger->fresh()->balance->fresh()->balance_cents);
        $this->assertSame(0, $balance, 'The trust balance must not move until a second firm user approves and posts the deposit.');
    }

    public function test_a_trust_deposit_with_no_trust_ledger_lands_in_pending_review_with_a_clear_reason(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::TrustDeposit, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 10000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));
        $result = $checkout->submitPayment($paymentRequest->fresh(), 10000);

        $this->assertSame(PaymentRequestStatus::PendingReview, $result->status);
        $this->assertStringContainsString('no trust ledger', (string) $result->failure_reason);
    }

    public function test_submitting_a_non_active_request_throws(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        // Deliberately never activated — stays Draft.

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));

        $this->expectException(\RuntimeException::class);
        $checkout->submitPayment($paymentRequest->fresh(), 5000);
    }

    public function test_a_paid_request_cannot_be_submitted_again(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));
        $first = $checkout->submitPayment($paymentRequest->fresh(), 5000);
        $this->assertSame(PaymentRequestStatus::Paid, $first->status);

        $this->expectException(\RuntimeException::class);
        $checkout->submitPayment($first->fresh(), 5000);
    }

    public function test_an_up_to_amount_above_the_remaining_balance_is_rejected_even_if_the_browser_sends_it(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'total_cents' => 10000,
            'amount_paid_cents' => 0,
        ]));

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::UpTo, $creator,
            invoice: $invoice,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));

        $this->expectException(\RuntimeException::class);
        $checkout->submitPayment($paymentRequest->fresh(), 999999);
    }

    public function test_the_provider_response_persisted_on_the_event_never_carries_more_than_the_allowlisted_fields(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $paymentRequest = app(PaymentRequestService::class)->create(
            $firm, $client, PaymentRequestPurpose::EarnedFee, PaymentRequestAmountRule::Fixed, $creator,
            requestedAmountCents: 5000,
        );
        app(PaymentRequestService::class)->activate($firm, $paymentRequest->fresh(), $creator);

        $checkout = $this->checkoutServiceWithGateway(new FakeStripeGateway(shouldSucceed: true));
        $result = $checkout->submitPayment($paymentRequest->fresh(), 5000);

        $confirmedEvent = $this->runWithFirmContext($firm, fn () => PaymentRequestEvent::query()
            ->where('payment_request_id', $result->id)
            ->where('event_type', PaymentRequestEventType::ProviderConfirmed->value)
            ->first());

        $this->assertNotNull($confirmedEvent);
        $this->assertEqualsCanonicalizing(['status', 'id'], array_keys(array_filter($confirmedEvent->provider_response_json, fn ($v) => $v !== null)));
    }

    /**
     * Proves the idempotency mechanism PaymentRequestCheckoutService
     * relies on: "payment_request:{uuid}:{provider_transaction_id}" is
     * passed straight into ManualPaymentService::submit()'s own
     * idempotency_key handling. A retried confirmation for the exact
     * same provider transaction (e.g. a future webhook redelivery)
     * must return the ORIGINAL Payment, never create a second one.
     */
    public function test_the_idempotency_key_construction_prevents_a_duplicate_payment_for_a_retried_provider_transaction(): void
    {
        $firm = $this->enabledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $paymentRequestUuid = (string) Str::uuid7();
        $providerTransactionId = 'fake_pi_retry_test';
        $idempotencyKey = "payment_request:{$paymentRequestUuid}:{$providerTransactionId}";

        $first = app(ManualPaymentService::class)->submit(
            $firm, $client, 5000, ManualPaymentMethod::PaymentLink, PaymentClassification::OperatingPayment,
            $idempotencyKey,
        );

        $second = app(ManualPaymentService::class)->submit(
            $firm, $client, 5000, ManualPaymentMethod::PaymentLink, PaymentClassification::OperatingPayment,
            $idempotencyKey,
        );

        $this->assertSame($first->id, $second->id, 'A retried confirmation for the same provider transaction must return the original Payment, never a duplicate.');
        $count = $this->runWithFirmContext($firm, fn () => Payment::query()->where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(1, $count);
    }
}
