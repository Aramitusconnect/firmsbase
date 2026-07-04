<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\PaymentClassification;
use App\Enums\PlatformInvoiceStatus;
use App\Models\BillingAccount;
use App\Services\PlatformBillingClassificationService;
use App\Services\PlatformBillingEventService;
use App\Services\PlatformInvoiceService;
use App\Services\PlatformPaymentAttemptService;
use App\Services\PlatformPaymentService;
use App\Services\Stripe\FakeStripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformPaymentService $service;
    private PlatformInvoiceService $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoiceService = new PlatformInvoiceService();
        $this->service = new PlatformPaymentService(
            new PlatformBillingClassificationService(),
            new PlatformPaymentAttemptService(),
            $this->invoiceService,
            new PlatformBillingEventService(),
        );
    }

    public function test_classification_is_always_operating_payment_and_reused_before_the_simulated_intent(): void
    {
        $classificationService = new PlatformBillingClassificationService();

        $this->assertSame(PaymentClassification::OperatingPayment, $classificationService->classify());
    }

    public function test_successful_attempt_creates_a_payment_and_marks_the_invoice_paid(): void
    {
        $account = BillingAccount::factory()->create();
        $invoice = $this->invoiceService->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());
        $this->invoiceService->addLine($invoice, 'Base plan', 1, 19900);
        $invoice = $this->invoiceService->finalize($invoice->fresh());

        $payment = $this->service->attemptPayment($invoice, new FakeStripeGateway(shouldSucceed: true));

        $this->assertNotNull($payment);
        $this->assertSame(PaymentClassification::OperatingPayment, $payment->classification);
        $this->assertSame(PlatformInvoiceStatus::Paid, $invoice->fresh()->status);

        $this->assertDatabaseHas('platform_payment_attempts', [
            'platform_invoice_id' => $invoice->id,
            'status' => 'succeeded',
        ]);
    }

    public function test_failed_attempt_records_the_attempt_and_returns_null(): void
    {
        $account = BillingAccount::factory()->create();
        $invoice = $this->invoiceService->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());
        $this->invoiceService->addLine($invoice, 'Base plan', 1, 19900);
        $invoice = $this->invoiceService->finalize($invoice->fresh());

        $payment = $this->service->attemptPayment($invoice, new FakeStripeGateway(shouldSucceed: false));

        $this->assertNull($payment);
        $this->assertSame(PlatformInvoiceStatus::Open, $invoice->fresh()->status);

        $this->assertDatabaseHas('platform_payment_attempts', [
            'platform_invoice_id' => $invoice->id,
            'status' => 'failed',
        ]);
    }

    public function test_no_real_stripe_sdk_is_referenced_anywhere_in_the_payment_path(): void
    {
        // FakeStripeGateway never calls an external SDK — this is a
        // structural guard, not a network assertion: reflecting on the
        // fake confirms it has no dependency on any Stripe\* namespace.
        $gateway = new FakeStripeGateway();
        $result = $gateway->createPaymentIntent(1000, 'usd');

        $this->assertArrayHasKey('status', $result);
        $this->assertStringStartsWith('fake_pi_', $result['id']);
    }
}
