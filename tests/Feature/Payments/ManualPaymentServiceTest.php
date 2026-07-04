<?php

namespace Tests\Feature\Payments;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentMode;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentBlockedException;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Services\ManualPaymentService;
use App\Services\PaymentApplicationService;
use App\Services\PaymentClassificationService;
use App\Services\PaymentPlanService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManualPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $timeline = new TimelineEventRecorder();
        $this->service = new ManualPaymentService(
            new PaymentClassificationService(),
            new PaymentApplicationService(new PaymentPlanService($timeline), $timeline),
            $timeline,
        );
    }

    public function test_accepted_operating_payment_creates_a_manual_payment_record_linked_to_the_canonical_payment(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $payment = $this->service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(PaymentClassification::OperatingPayment, $payment->payment_classification);
        $this->assertNotNull($payment->manualPaymentRecord);
        $this->assertSame($payment->id, $payment->manualPaymentRecord->payment_id);
    }

    public function test_blocked_payment_cannot_be_saved_as_a_successful_payment(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        try {
            $this->service->submit(
                $firm,
                $client,
                amountCents: 20000,
                method: ManualPaymentMethod::Check,
                requestedClassification: PaymentClassification::BlockedPayment,
                idempotencyKey: (string) Str::uuid(),
            );
            $this->fail('Expected PaymentBlockedException was not thrown.');
        } catch (PaymentBlockedException $e) {
            $this->assertSame(PaymentStatus::Blocked, $e->payment->status);
            $this->assertNotSame(PaymentStatus::Succeeded, $e->payment->status);
        }

        // No manual_payment_records row exists for the blocked attempt.
        $this->assertDatabaseCount('manual_payment_records', 0);
    }

    public function test_usa_saas_trust_iolta_payment_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['payment_mode' => PaymentMode::OperatingAndTrust]);
        $client = Client::factory()->forFirm($firm)->create();

        $this->expectException(PaymentBlockedException::class);

        $this->service->submit(
            $firm,
            $client,
            amountCents: 50000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::TrustIoltaPayment,
            idempotencyKey: (string) Str::uuid(),
        );
    }

    public function test_a_payment_classification_event_is_created_for_a_blocked_attempt(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        try {
            $this->service->submit(
                $firm,
                $client,
                amountCents: 20000,
                method: ManualPaymentMethod::Check,
                requestedClassification: PaymentClassification::TrustIoltaPayment,
                idempotencyKey: (string) Str::uuid(),
            );
        } catch (PaymentBlockedException $e) {
            $this->assertDatabaseHas('payment_classification_events', [
                'payment_id' => $e->payment->id,
                'event_type' => 'classification_blocked',
                'requested_classification' => PaymentClassification::TrustIoltaPayment->value,
                'resolved_classification' => PaymentClassification::BlockedPayment->value,
            ]);

            return;
        }

        $this->fail('Expected PaymentBlockedException was not thrown.');
    }

    public function test_manual_double_submission_with_the_same_idempotency_key_does_not_create_duplicate_payments(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $key = (string) Str::uuid();

        $first = $this->service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: $key,
        );

        $second = $this->service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: $key,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::where('firm_id', $firm->id)->where('idempotency_key', $key)->count());
        $this->assertSame(1, \App\Models\ManualPaymentRecord::whereHas('payment', fn ($q) => $q->where('idempotency_key', $key))->count());
    }

    public function test_double_submission_replays_the_original_blocked_outcome_rather_than_retrying(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $key = (string) Str::uuid();

        $blockedPaymentId = null;

        try {
            $this->service->submit(
                $firm, $client, 20000, ManualPaymentMethod::Check,
                PaymentClassification::TrustIoltaPayment, $key,
            );
        } catch (PaymentBlockedException $e) {
            $blockedPaymentId = $e->payment->id;
        }

        $this->assertNotNull($blockedPaymentId);

        $this->expectException(PaymentBlockedException::class);

        $this->service->submit(
            $firm, $client, 20000, ManualPaymentMethod::Check,
            PaymentClassification::TrustIoltaPayment, $key,
        );

        $this->assertSame(1, Payment::where('firm_id', $firm->id)->where('idempotency_key', $key)->count());
    }

    public function test_the_database_unique_index_prevents_two_payments_sharing_an_idempotency_key_for_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $key = (string) Str::uuid();
        Payment::factory()->forFirm($firm)->idempotencyKey($key)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Payment::factory()->forFirm($firm)->idempotencyKey($key)->create();
    }

    public function test_accepted_payment_applies_to_a_targeted_installment(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = PaymentPlan::factory()->forClient($client)->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->create(['amount_cents' => 20000]);

        $payment = $this->service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: (string) Str::uuid(),
            installment: $installment,
        );

        $this->assertSame(PaymentPlanInstallmentStatus::Paid, $installment->fresh()->status);
        $this->assertSame(20000, $installment->fresh()->paid_amount_cents);
        $this->assertSame($installment->id, $payment->payment_plan_installment_id);
    }
}
