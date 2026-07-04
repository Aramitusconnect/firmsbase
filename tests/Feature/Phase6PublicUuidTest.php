<?php

namespace Tests\Feature;

use App\Models\LicenseEvent;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\PlanModule;
use App\Models\PlatformBillingEvent;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use App\Models\PlatformPayment;
use App\Models\PlatformPaymentAttempt;
use App\Models\PlatformRefund;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionItem;
use App\Models\SeatAllocation;
use App\Models\SeatPool;
use App\Models\TemplateUpgradeLog;
use App\Models\TemplateUpgradePreview;
use App\Models\UsageRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers every Phase 6 model carrying a public UUIDv7 identifier via
 * App\Models\Concerns\HasPublicUuid (16 of 18 new models).
 * LicenseEvent and PlatformBillingEvent deliberately do NOT carry a
 * uuid — both are internal, append-only audit logs accessed only
 * through their parent, mirroring firm_entitlement_events'/
 * firm_activation_events' precedent.
 */
class Phase6PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    public static function modelProvider(): array
    {
        return [
            'Plan' => [Plan::class],
            'PlanModule' => [PlanModule::class],
            'PlanLimit' => [PlanLimit::class],
            'OrgLicense' => [OrgLicense::class],
            'SeatPool' => [SeatPool::class],
            'SeatAllocation' => [SeatAllocation::class],
            'PlatformSubscription' => [PlatformSubscription::class],
            'PlatformSubscriptionItem' => [PlatformSubscriptionItem::class],
            'PlatformInvoice' => [PlatformInvoice::class],
            'PlatformInvoiceLine' => [PlatformInvoiceLine::class],
            'PlatformPayment' => [PlatformPayment::class],
            'PlatformRefund' => [PlatformRefund::class],
            'PlatformPaymentAttempt' => [PlatformPaymentAttempt::class],
            'UsageRollup' => [UsageRollup::class],
            'TemplateUpgradePreview' => [TemplateUpgradePreview::class],
            'TemplateUpgradeLog' => [TemplateUpgradeLog::class],
        ];
    }

    #[DataProvider('modelProvider')]
    public function test_model_receives_a_uuid_on_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertNotNull($model->uuid);
        $this->assertNotEmpty($model->uuid);
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_a_valid_uuidv7(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $model->uuid,
            "{$modelClass}::uuid must be a version-7 UUID"
        );
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_immutable_after_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('uuid is immutable');

        $model->uuid = (string) \Illuminate\Support\Str::uuid7();
        $model->save();
    }

    public function test_license_event_does_not_carry_a_public_uuid(): void
    {
        $event = LicenseEvent::factory()->create();

        $this->assertArrayNotHasKey('uuid', $event->getAttributes());
    }

    public function test_platform_billing_event_does_not_carry_a_public_uuid(): void
    {
        $event = PlatformBillingEvent::factory()->create();

        $this->assertArrayNotHasKey('uuid', $event->getAttributes());
    }
}
