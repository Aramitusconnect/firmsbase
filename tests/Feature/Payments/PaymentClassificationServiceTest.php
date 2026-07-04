<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentClassification;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\Payment;
use App\Services\PaymentClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentClassificationService();
    }

    public function test_operating_payment_is_accepted(): void
    {
        $firm = Firm::factory()->create();

        $result = $this->service->classify($firm, PaymentClassification::OperatingPayment);

        $this->assertTrue($result->accepted);
        $this->assertSame(PaymentClassification::OperatingPayment, $result->resolvedClassification);
        $this->assertSame(PaymentStatus::Succeeded, $result->status);
        $this->assertNull($result->rejectionReason);
    }

    public function test_trust_iolta_payment_is_always_blocked_in_usa_saas_regardless_of_firm_payment_mode(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['payment_mode' => PaymentMode::OperatingAndTrust]);

        $result = $this->service->classify($firm, PaymentClassification::TrustIoltaPayment);

        $this->assertFalse($result->accepted);
        $this->assertSame(PaymentClassification::BlockedPayment, $result->resolvedClassification);
        $this->assertSame(PaymentStatus::Blocked, $result->status);
        $this->assertStringContainsString('Phase 13', $result->rejectionReason);
    }

    public function test_explicit_blocked_payment_stays_blocked(): void
    {
        $firm = Firm::factory()->create();

        $result = $this->service->classify($firm, PaymentClassification::BlockedPayment);

        $this->assertFalse($result->accepted);
        $this->assertSame(PaymentClassification::BlockedPayment, $result->resolvedClassification);
    }

    public function test_operating_payment_is_blocked_when_firm_payment_mode_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['payment_mode' => PaymentMode::Blocked]);

        $result = $this->service->classify($firm, PaymentClassification::OperatingPayment);

        $this->assertFalse($result->accepted);
        $this->assertSame(PaymentClassification::BlockedPayment, $result->resolvedClassification);
        $this->assertStringContainsString('disabled for this firm', $result->rejectionReason);
    }

    public function test_record_decision_updates_the_payment_and_creates_a_classification_event_for_an_accepted_payment(): void
    {
        $firm = Firm::factory()->create();
        $payment = Payment::factory()->forFirm($firm)->create([
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Initiated,
        ]);

        $result = $this->service->classify($firm, PaymentClassification::OperatingPayment);
        $event = $this->service->recordDecision($payment, PaymentClassification::OperatingPayment, $result);

        $this->assertSame(PaymentClassification::OperatingPayment, $payment->fresh()->payment_classification);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame('classification_accepted', $event->event_type);
        $this->assertSame($payment->id, $event->payment_id);
    }

    public function test_record_decision_updates_the_payment_and_creates_a_classification_event_for_a_blocked_payment(): void
    {
        $firm = Firm::factory()->create();
        $payment = Payment::factory()->forFirm($firm)->create([
            'payment_classification' => PaymentClassification::TrustIoltaPayment,
            'status' => PaymentStatus::Initiated,
        ]);

        $result = $this->service->classify($firm, PaymentClassification::TrustIoltaPayment);
        $event = $this->service->recordDecision($payment, PaymentClassification::TrustIoltaPayment, $result);

        $this->assertSame(PaymentClassification::BlockedPayment, $payment->fresh()->payment_classification);
        $this->assertSame(PaymentStatus::Blocked, $payment->fresh()->status);
        $this->assertSame('classification_blocked', $event->event_type);
        $this->assertSame(PaymentClassification::TrustIoltaPayment, $event->requested_classification);
        $this->assertSame(PaymentClassification::BlockedPayment, $event->resolved_classification);
    }
}
