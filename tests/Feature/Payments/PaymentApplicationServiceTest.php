<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentClassification;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Services\PaymentApplicationService;
use App\Services\PaymentPlanService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $timeline = new TimelineEventRecorder();
        $this->service = new PaymentApplicationService(new PaymentPlanService($timeline), $timeline);
    }

    public function test_apply_to_installment_marks_it_paid_when_fully_covered(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->create(['amount_cents' => 10000]);
        $payment = Payment::factory()->forClient($client)->create([
            'payment_plan_installment_id' => $installment->id,
            'amount_cents' => 10000,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]);

        $this->service->applyToInstallment($payment, $installment);

        $this->assertSame(PaymentPlanInstallmentStatus::Paid, $installment->fresh()->status);
        $this->assertSame(10000, $installment->fresh()->paid_amount_cents);
        $this->assertNotNull($installment->fresh()->paid_at);
    }

    public function test_apply_to_installment_marks_it_partially_paid_when_underpaid(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->create(['amount_cents' => 10000]);
        $payment = Payment::factory()->forClient($client)->create([
            'payment_plan_installment_id' => $installment->id,
            'amount_cents' => 4000,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]);

        $this->service->applyToInstallment($payment, $installment);

        $this->assertSame(PaymentPlanInstallmentStatus::PartiallyPaid, $installment->fresh()->status);
        $this->assertSame(4000, $installment->fresh()->paid_amount_cents);
    }

    public function test_apply_to_installment_rejects_a_blocked_payment(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->create();
        $payment = Payment::factory()->forClient($client)->blocked()->create(['payment_plan_installment_id' => $installment->id]);

        $this->expectException(\RuntimeException::class);

        $this->service->applyToInstallment($payment, $installment);
    }

    public function test_completing_the_final_installment_completes_the_plan(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->create(['amount_cents' => 10000]);
        $payment = Payment::factory()->forClient($client)->create([
            'payment_plan_installment_id' => $installment->id,
            'amount_cents' => 10000,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]);

        $this->service->applyToInstallment($payment, $installment);

        $this->assertSame(\App\Enums\PaymentPlanStatus::Completed, $plan->fresh()->status);
    }

    public function test_apply_to_invoice_marks_it_paid_when_fully_covered(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $invoice = Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->totals(10000)->create();
        $payment = Payment::factory()->forClient($client)->create([
            'invoice_id' => $invoice->id,
            'amount_cents' => 10000,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]);

        $this->service->applyToInvoice($payment, $invoice);

        $reFetched = $this->runWithFirmContext($firm, fn () => $invoice->fresh());
        $this->assertSame(InvoiceStatus::Paid, $reFetched->status);
        $this->assertSame(10000, $reFetched->amount_paid_cents);
    }

    public function test_apply_to_invoice_throws_when_invoice_is_still_a_draft(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $invoice = Invoice::factory()->forClient($client)->totals(10000)->create(); // draft
        $payment = Payment::factory()->forClient($client)->create([
            'invoice_id' => $invoice->id,
            'amount_cents' => 10000,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->applyToInvoice($payment, $invoice);
    }
}
