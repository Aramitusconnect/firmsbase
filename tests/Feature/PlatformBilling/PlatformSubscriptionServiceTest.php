<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\PlatformSubscriptionStatus;
use App\Enums\SeatClass;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlatformSubscriptionService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformSubscriptionService;
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

    // ------------------------------------------------------------
    // Phase 3 FirmsVault Admin Control Center additions — actor +
    // audit plumbing on cancel().
    // ------------------------------------------------------------

    public function test_cancel_without_an_actor_writes_no_audit_event_and_behaves_exactly_as_before(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = $this->service->subscribe($account, $plan, now(), now()->addMonth());

        $cancelled = $this->service->cancel($subscription, atPeriodEnd: false);

        $this->assertSame(PlatformSubscriptionStatus::Cancelled, $cancelled->status);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'subscription_cancelled')->count()
        );
        $this->assertSame(0, $count, 'No actor supplied means no audit event and no behavior change from before this addition.');
    }

    public function test_cancel_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = $this->service->subscribe($account, $plan, now(), now()->addMonth());

        $cancelled = $this->service->cancel($subscription, atPeriodEnd: false, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'subscription_cancelled')->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id);
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('platform_billing', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($cancelled->id, $metadata['platform_subscription_id']);
        $this->assertSame($account->id, $metadata['billing_account_id']);
        $this->assertFalse($metadata['at_period_end']);
        $this->assertSame('cancelled', $metadata['resulting_status']);

        // No PII/secret leakage: only numeric ids, a bool, and an enum
        // value string are present in metadata.
        $this->assertEqualsCanonicalizing(
            ['platform_subscription_id', 'billing_account_id', 'at_period_end', 'resulting_status'],
            array_keys($metadata)
        );
    }
}
