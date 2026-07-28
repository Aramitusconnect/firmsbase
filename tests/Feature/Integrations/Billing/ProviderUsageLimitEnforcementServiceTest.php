<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderOperationPolicy;
use App\Integrations\Billing\ProviderUsageLimitEnforcementService;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ProviderHardLimitExceededException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Services\IntegrationUsageRecorderService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ProviderUsageLimitEnforcementServiceTest — checkpoint4-design-cost-control.md
 * §2 step 11. Proves the soft-limit (logged, call proceeds) vs.
 * hard-limit (call blocked, ProviderHardLimitExceededException) paths,
 * and that the reservation-INCLUSIVE sum (finalized usage PLUS
 * currently-`reserved` rows) is what is actually compared against each
 * limit — the TOCTOU-closing behavior the design's own docblock
 * describes.
 */
class ProviderUsageLimitEnforcementServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderUsageLimitEnforcementService $service;

    private ProviderBillingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProviderUsageLimitEnforcementService();
        $this->classifier = new ProviderBillingClassifier();
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function reservation(Firm $firm, FirmIntegration $connection, string $status = ProviderBillableCallReservation::STATUS_RESERVED): void
    {
        $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'billing_operation' => 'download',
            'environment' => 'production',
            'quantity' => 1,
            'unit' => 'request',
            'status' => $status,
            'idempotency_key' => (string) Str::uuid7(),
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(2),
            'finalized_at' => $status === ProviderBillableCallReservation::STATUS_RESERVED ? null : now(),
        ]));
    }

    private function finalizedUsage(Firm $firm, FirmIntegration $connection, int $quantity = 1): void
    {
        $this->createWithFirmContext($firm, fn () => (new IntegrationUsageRecorderService())->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: ProviderKey::Plaid->value,
            capability: 'statements:download',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            unit: 'request',
            outcome: 'success',
            idempotencyKey: (string) Str::uuid7(),
            quantity: $quantity,
        ));
    }

    public function test_no_limits_configured_never_blocks_and_reports_no_soft_breach(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(null, null, 86400, 0, null);

        $softBreach = $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);

        $this->assertFalse($softBreach);
    }

    public function test_a_call_under_both_limits_proceeds_with_no_soft_breach(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: 10, hardLimitQuantity: 20, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        $softBreach = $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);

        $this->assertFalse($softBreach);
    }

    public function test_exceeding_the_soft_limit_but_not_the_hard_limit_proceeds_and_reports_a_breach(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->finalizedUsage($firm, $connection, 5);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: 5, hardLimitQuantity: 20, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        $softBreach = $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);

        $this->assertTrue($softBreach);
    }

    public function test_exceeding_the_hard_limit_throws_and_carries_the_limit_and_attempted_total(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->finalizedUsage($firm, $connection, 10);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: 5, hardLimitQuantity: 10, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        try {
            $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);
            $this->fail('Expected ProviderHardLimitExceededException.');
        } catch (ProviderHardLimitExceededException $e) {
            $this->assertSame(10, $e->limit);
            $this->assertSame(11, $e->attemptedTotal);
        }
    }

    public function test_currently_reserved_rows_count_toward_the_limit_even_before_finalization(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        // No finalized usage at all — only an in-flight reservation.
        $this->reservation($firm, $connection, ProviderBillableCallReservation::STATUS_RESERVED);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: null, hardLimitQuantity: 1, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        $this->expectException(ProviderHardLimitExceededException::class);

        $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);
    }

    public function test_a_reservation_that_is_already_finalized_does_not_double_count_against_the_limit(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        // finalized_billable reservations are not counted directly by
        // this service — only currently-`reserved` rows and finalized
        // integration_usage_records rows are. A finalized reservation
        // with no matching usage record must not, by itself, count
        // twice or block a call under the hard limit.
        $this->reservation($firm, $connection, ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: null, hardLimitQuantity: 1, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        $softBreach = $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);

        $this->assertFalse($softBreach);
    }

    public function test_usage_outside_the_limit_window_does_not_count(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->createWithFirmContext($firm, fn () => (new IntegrationUsageRecorderService())->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: ProviderKey::Plaid->value,
            capability: 'statements:download',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            unit: 'request',
            outcome: 'success',
            idempotencyKey: (string) Str::uuid7(),
            occurredAt: now()->subDays(2),
            quantity: 50,
        ));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        // A 1-hour window excludes usage recorded two days ago.
        $policy = new ProviderOperationPolicy(softLimitQuantity: null, hardLimitQuantity: 10, limitWindowSeconds: 3600, cooldownSeconds: 0, cacheTtlSeconds: null);

        $softBreach = $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);

        $this->assertFalse($softBreach);
    }

    public function test_current_period_total_is_a_read_only_preview_with_no_enforcement(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->finalizedUsage($firm, $connection, 999);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: 1, hardLimitQuantity: 1, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        // Must not throw, despite wildly exceeding both limits — this
        // is the preview path ProviderLiveBalanceConfirmationService::prepare()
        // relies on.
        $total = $this->service->currentPeriodTotal($firm, $connection, ProviderKey::Plaid, $classification, $policy);

        $this->assertSame(999, $total);
    }

    public function test_usage_for_a_different_capability_never_counts_against_this_ones_limit(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->createWithFirmContext($firm, fn () => (new IntegrationUsageRecorderService())->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: ProviderKey::Plaid->value,
            capability: 'balance:get',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: null,
            unit: 'request',
            outcome: 'success',
            idempotencyKey: (string) Str::uuid7(),
            quantity: 999,
        ));
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $policy = new ProviderOperationPolicy(softLimitQuantity: 1, hardLimitQuantity: 1, limitWindowSeconds: 86400, cooldownSeconds: 0, cacheTtlSeconds: null);

        $softBreach = $this->service->assertWithinLimits($firm, $connection, ProviderKey::Plaid, $classification, $policy, 1);

        $this->assertFalse($softBreach);
    }
}
