<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\PlatformSubscriptionStatus;
use App\Enums\SeatClass;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Services\PlatformSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformSubscriptionService();
    }

    public function test_subscribe_creates_an_active_subscription_without_a_trial(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();

        $subscription = $this->service->subscribe($account, $plan, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);
        $this->assertSame($account->id, $subscription->billing_account_id);
    }

    public function test_subscribe_with_trial_end_creates_a_trialing_subscription(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();

        $subscription = $this->service->subscribe(
            $account, $plan, now()->startOfMonth(), now()->endOfMonth(), now()->addDays(14)
        );

        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
    }

    public function test_cancel_at_period_end_does_not_immediately_cancel(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = $this->service->subscribe($account, $plan, now(), now()->addMonth());

        $cancelled = $this->service->cancel($subscription, atPeriodEnd: true);

        $this->assertSame(PlatformSubscriptionStatus::Active, $cancelled->status);
        $this->assertTrue($cancelled->cancel_at_period_end);
    }

    public function test_cancel_immediately(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = $this->service->subscribe($account, $plan, now(), now()->addMonth());

        $cancelled = $this->service->cancel($subscription, atPeriodEnd: false);

        $this->assertSame(PlatformSubscriptionStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_add_item_creates_a_seat_addon_line(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = $this->service->subscribe($account, $plan, now(), now()->addMonth());

        $item = $this->service->addItem($subscription, 'seat_addon', 3, 1500, SeatClass::Attorney);

        $this->assertSame(SeatClass::Attorney, $item->seat_class);
        $this->assertSame(3, $item->quantity);
    }
}
