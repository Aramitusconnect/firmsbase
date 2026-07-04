<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\PlatformPaymentStatus;
use App\Enums\PlatformRefundStatus;
use App\Models\PlatformPayment;
use App\Services\PlatformRefundService;
use App\Services\Stripe\FakeStripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformRefundService();
    }

    public function test_full_refund_marks_the_payment_refunded(): void
    {
        $payment = PlatformPayment::factory()->create(['amount_cents' => 10000]);

        $refund = $this->service->refund($payment, 10000, 'Customer cancelled', new FakeStripeGateway());

        $this->assertSame(PlatformRefundStatus::Completed, $refund->status);
        $this->assertSame(PlatformPaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_partial_refund_marks_the_payment_partially_refunded(): void
    {
        $payment = PlatformPayment::factory()->create(['amount_cents' => 10000]);

        $this->service->refund($payment, 4000, 'Partial credit', new FakeStripeGateway());

        $this->assertSame(PlatformPaymentStatus::PartiallyRefunded, $payment->fresh()->status);
    }

    public function test_refund_exceeding_remaining_balance_is_rejected(): void
    {
        $payment = PlatformPayment::factory()->create(['amount_cents' => 10000]);
        $this->service->refund($payment, 7000, 'First refund', new FakeStripeGateway());

        $this->expectException(\RuntimeException::class);

        $this->service->refund($payment->fresh(), 5000, 'Second refund', new FakeStripeGateway());
    }
}
