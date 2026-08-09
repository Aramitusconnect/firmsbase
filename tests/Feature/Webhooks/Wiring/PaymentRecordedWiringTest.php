<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\WebhookEventType;
use App\Exceptions\PaymentBlockedException;
use App\Models\Client;
use App\Models\Payment;
use App\Services\ManualPaymentService;
use App\Services\OperatingJournalRecorderService;
use App\Services\PaymentApplicationService;
use App\Services\PaymentClassificationService;
use App\Services\PaymentPlanService;
use App\Services\TimelineEventRecorder;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * payment.recorded is wired at the single real owner (Phase 14b
 * decision G): ManualPaymentService::submit(), only inside the
 * $result->accepted branch. The existing idempotency-key early return
 * (before that branch) means a repeated submission never reaches the
 * webhook call at all.
 */
class PaymentRecordedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    private function makeService(): ManualPaymentService
    {
        $timeline = new TimelineEventRecorder;

        return new ManualPaymentService(
            new PaymentClassificationService,
            new PaymentApplicationService(new PaymentPlanService($timeline), $timeline),
            $timeline,
            app(OperatingJournalRecorderService::class),
        );
    }

    public function test_payment_recorded_fires_exactly_once_for_an_accepted_operating_payment(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $service = $this->makeService();

        $payment = $service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::PaymentRecorded->value,
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
        ]);
    }

    public function test_payment_recorded_does_not_fire_for_a_blocked_payment(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $service = $this->makeService();

        try {
            $service->submit(
                $firm,
                $client,
                amountCents: 20000,
                method: ManualPaymentMethod::Check,
                requestedClassification: PaymentClassification::TrustIoltaPayment,
                idempotencyKey: (string) Str::uuid(),
            );
            $this->fail('Expected a PaymentBlockedException for a trust/IOLTA classification.');
        } catch (PaymentBlockedException) {
            // expected — trust/IOLTA deposits remain blocked (project rule)
        }

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_repeated_idempotency_key_does_not_fire_a_second_webhook_event(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $service = $this->makeService();
        $idempotencyKey = (string) Str::uuid();

        $service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: $idempotencyKey,
        );

        // Same idempotency key again — must return the original payment
        // via the early-return branch and must NOT create a second
        // webhook_events row.
        $service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: $idempotencyKey,
        );

        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseCount('payments', 1));
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_recorder_exception_does_not_break_manual_payment_submission(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $client = Client::factory()->forFirm($firm)->create();
        $service = $this->makeService();

        $payment = $service->submit(
            $firm,
            $client,
            amountCents: 20000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount_cents' => 20000]));
        $this->assertNotNull($payment->manualPaymentRecord);
    }
}
