<?php

namespace Tests\Feature\Commissions;

use App\Enums\CommissionEventType;
use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentStatus;
use App\Models\BillingAccount;
use App\Models\CommissionPlan;
use App\Models\OrgLicense;
use App\Models\PlatformBillingEvent;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Services\CommissionEligibilityService;
use App\Services\CommissionEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommissionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionEventService $service;
    private CommissionEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eligibilityService = new CommissionEligibilityService();
        $this->service = new CommissionEventService($this->eligibilityService);
    }

    public function test_unpaid_platform_invoice_blocks_payable_commission(): void
    {
        $billingAccount = BillingAccount::factory()->create();
        $invoice = PlatformInvoice::factory()->create([
            'billing_account_id' => $billingAccount->id,
            'status' => PlatformInvoiceStatus::Open->value,
        ]);
        $commissionPlan = CommissionPlan::factory()->create();
        $orgLicense = OrgLicense::factory()->create();

        $event = $this->service->attributeOnce(
            $billingAccount, $commissionPlan, $orgLicense, CommissionEventType::NewBusiness, 1000,
            platformInvoiceId: $invoice->id,
        );

        $this->assertFalse($event->status->value === 'payable');
        $this->assertStringContainsString('platform_invoice_unpaid', $event->blocked_reason);
    }

    public function test_refunded_platform_payment_blocks_payable_commission(): void
    {
        $billingAccount = BillingAccount::factory()->create();
        $payment = PlatformPayment::factory()->create([
            'billing_account_id' => $billingAccount->id,
            'status' => PlatformPaymentStatus::Refunded->value,
        ]);
        $commissionPlan = CommissionPlan::factory()->create();
        $orgLicense = OrgLicense::factory()->create();

        $event = $this->service->attributeOnce(
            $billingAccount, $commissionPlan, $orgLicense, CommissionEventType::NewBusiness, 1000,
            platformPaymentId: $payment->id,
        );

        $this->assertStringContainsString('platform_payment_refunded', $event->blocked_reason);
    }

    #[DataProvider('disqualifyingBillingEventProvider')]
    public function test_disqualifying_billing_events_block_commission(string $eventType): void
    {
        $billingAccount = BillingAccount::factory()->create();
        PlatformBillingEvent::create([
            'billing_account_id' => $billingAccount->id,
            'event_type' => $eventType,
        ]);
        $commissionPlan = CommissionPlan::factory()->create();
        $orgLicense = OrgLicense::factory()->create();

        $event = $this->service->attributeOnce($billingAccount, $commissionPlan, $orgLicense, CommissionEventType::NewBusiness, 1000);

        $this->assertStringContainsString($eventType, $event->blocked_reason);
    }

    public static function disqualifyingBillingEventProvider(): array
    {
        return [
            ['payment_disputed'],
            ['payment_charged_back'],
            ['invoice_cancelled'],
            ['invoice_blocked'],
            ['account_blocked'],
            ['payment_holding_period'],
            ['refund_created'],
            ['refund_processed'],
        ];
    }

    public function test_holding_period_blocks_commission_until_it_elapses(): void
    {
        $billingAccount = BillingAccount::factory()->create();
        $commissionPlan = CommissionPlan::factory()->create(['holding_period_days' => 30]);
        $orgLicense = OrgLicense::factory()->create();

        $event = $this->service->attributeOnce($billingAccount, $commissionPlan, $orgLicense, CommissionEventType::NewBusiness, 1000);

        $this->assertStringContainsString('holding_period_active', $event->blocked_reason);

        $event->update(['holding_period_ends_at' => now()->subDay()]);
        $refreshed = $this->service->refreshEligibility($event->fresh());

        $this->assertSame('payable', $refreshed->status->value);
    }

    public function test_commission_never_uses_firm_client_payments(): void
    {
        $result = $this->eligibilityService instanceof CommissionEligibilityService;
        $this->assertTrue($result);

        // CommissionEligibilityService::evaluate() only ever loads
        // CommissionEvent::platformInvoice()/platformPayment() (Phase 6
        // platform billing relations) — there is no code path in this
        // service that touches App\Models\Invoice or App\Models\Payment.
        $source = file_get_contents(app_path('Services/CommissionEligibilityService.php'));
        $this->assertStringNotContainsString('App\\Models\\Invoice', $source);
        $this->assertStringNotContainsString('App\\Models\\Payment;', $source);
    }
}
