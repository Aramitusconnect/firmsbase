<?php

namespace Tests\Feature\PaymentPlans;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\PaymentPlanInstallment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPlanInstallmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $installment = PaymentPlanInstallment::factory()->create();

        $this->assertDatabaseHas('payment_plan_installments', ['id' => $installment->id]);
        $this->assertSame(PaymentPlanInstallmentStatus::Scheduled, $installment->status);
    }

    public function test_no_own_firm_id_column_exists(): void
    {
        $installment = PaymentPlanInstallment::factory()->create();

        $this->assertArrayNotHasKey('firm_id', $installment->getAttributes());
    }

    public function test_is_fully_paid(): void
    {
        $installment = PaymentPlanInstallment::factory()->create(['amount_cents' => 10000, 'paid_amount_cents' => 10000]);

        $this->assertTrue($installment->isFullyPaid());
    }
}
