<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Data\ProviderUsageAnomaly;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\IntegrationUsageRecorderService;
use App\Integrations\Services\ProviderUsageAnomalyDetectionService;
use App\Jobs\DetectProviderUsageAnomaliesJob;
use App\Models\Firm;
use App\Models\TimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ProviderUsageAnomalyDetectionServiceTest — checkpoint4-design-cost-control.md
 * §7. Proves `ProviderUsageAnomalyDetectionService::evaluate()` against
 * the concrete signal it actually implements: a rolling 30-day daily
 * baseline compared to the trailing 24-hour window, flagged when the
 * window exceeds `anomaly_multiplier` (default 3x) times the baseline,
 * plus the cold-start absolute-ceiling branch for a firm/product with
 * no 30-day history yet (`anomaly_cold_start_ceiling`, default 200).
 * Also proves `DetectProviderUsageAnomaliesJob` records a
 * `provider_billing.anomaly_detected` audit event via the existing
 * `TimelineEventRecorder` when it finds one.
 */
class ProviderUsageAnomalyDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderUsageAnomalyDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProviderUsageAnomalyDetectionService();
    }

    /**
     * Registered against the seeded `plaid` integration_providers row —
     * required for DetectProviderUsageAnomaliesJob::handle()'s own
     * `whereHas('integrationProvider', fn ($q) => $q->where('code',
     * 'plaid'))` connection filter to find this connection at all.
     * Harmless for the pure evaluate() tests too, which never inspect
     * the connection's registered provider.
     */
    private function connection(Firm $firm): FirmIntegration
    {
        $plaidProvider = IntegrationProvider::query()->where('code', 'plaid')->firstOrFail();

        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($plaidProvider)->create());
    }

    /**
     * `DetectProviderUsageAnomaliesJob` writes `provider_billing.anomaly_detected`
     * via `TimelineEventRecorder::record(..., independentOfAmbientTransaction: true)`
     * — a genuinely separate `pgsql_audit` PDO session (see that
     * recorder's own docblock), which can only see a Firm row that is
     * genuinely committed in another database session. A plain
     * `Firm::factory()->create()` is never committed for the whole
     * duration of a `RefreshDatabase`-wrapped test, so any test that
     * actually reaches a detected anomaly must create its Firm this way
     * instead — mirrors `IntegrationAccessPolicyServiceTest::cleanUpDurableFirmAuditTrailAfterRollback()`'s
     * own already-established precedent for the identical problem.
     */
    private function firmDurableForAudit(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        return $firm;
    }

    /**
     * MUST run via beforeApplicationDestroyed(), not an inline
     * try/finally in the test body — see
     * IntegrationAccessPolicyServiceTest's identical helper for the full
     * "why" (a FOR KEY SHARE lock held by the default connection's still-
     * open RefreshDatabase transaction deadlocks an earlier delete).
     */
    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
    }

    private function recordUsage(Firm $firm, FirmIntegration $connection, \DateTimeInterface $occurredAt, int $quantity = 1): void
    {
        $this->createWithFirmContext($firm, fn () => (new IntegrationUsageRecorderService())->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: ProviderKey::Plaid->value,
            capability: 'balance:get',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            unit: 'request',
            outcome: 'success',
            idempotencyKey: (string) Str::uuid7(),
            occurredAt: \Illuminate\Support\Carbon::instance($occurredAt),
            quantity: $quantity,
        ));
    }

    public function test_no_usage_at_all_never_flags_an_anomaly(): void
    {
        $firm = Firm::factory()->create();

        $anomaly = $this->service->evaluate($firm, ProviderKey::Plaid->value, 'balance');

        $this->assertNull($anomaly);
    }

    public function test_usage_consistent_with_the_trailing_baseline_never_flags_an_anomaly(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $windowEnd = now();

        // A steady ~5/day baseline for the prior 29 days, and 5 more in
        // the current 24h window — well within 3x the baseline.
        for ($day = 2; $day <= 29; $day++) {
            $this->recordUsage($firm, $connection, $windowEnd->clone()->subDays($day), 5);
        }
        $this->recordUsage($firm, $connection, $windowEnd->clone()->subHours(2), 5);

        $anomaly = $this->service->evaluate($firm, ProviderKey::Plaid->value, 'balance', $windowEnd);

        $this->assertNull($anomaly);
    }

    public function test_a_spike_well_beyond_the_multiplier_flags_a_non_cold_start_anomaly(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $windowEnd = now();

        // ~1/day baseline for the prior 29 days.
        for ($day = 2; $day <= 29; $day++) {
            $this->recordUsage($firm, $connection, $windowEnd->clone()->subDays($day), 1);
        }
        // A 10x spike in the current 24h window (default multiplier is 3x).
        $this->recordUsage($firm, $connection, $windowEnd->clone()->subHours(2), 10);

        $anomaly = $this->service->evaluate($firm, ProviderKey::Plaid->value, 'balance', $windowEnd);

        $this->assertInstanceOf(ProviderUsageAnomaly::class, $anomaly);
        $this->assertFalse($anomaly->coldStart);
        $this->assertSame(10, $anomaly->currentWindowCount);
    }

    public function test_a_new_firm_with_no_30_day_baseline_uses_the_cold_start_absolute_ceiling(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $windowEnd = now();

        // No baseline usage at all (cold start), but a huge current
        // window count exceeding the default cold-start ceiling (200).
        $this->recordUsage($firm, $connection, $windowEnd->clone()->subHours(1), 250);

        $anomaly = $this->service->evaluate($firm, ProviderKey::Plaid->value, 'balance', $windowEnd);

        $this->assertInstanceOf(ProviderUsageAnomaly::class, $anomaly);
        $this->assertTrue($anomaly->coldStart);
    }

    public function test_a_cold_start_firm_under_the_absolute_ceiling_is_never_flagged(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $windowEnd = now();

        $this->recordUsage($firm, $connection, $windowEnd->clone()->subHours(1), 50);

        $anomaly = $this->service->evaluate($firm, ProviderKey::Plaid->value, 'balance', $windowEnd);

        $this->assertNull($anomaly);
    }

    public function test_usage_for_a_different_product_never_contributes_to_this_products_evaluation(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $windowEnd = now();

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
            occurredAt: $windowEnd->clone()->subHours(1),
            quantity: 999,
        ));

        $anomaly = $this->service->evaluate($firm, ProviderKey::Plaid->value, 'balance', $windowEnd);

        $this->assertNull($anomaly);
    }

    // ------------------------------------------------------------
    // DetectProviderUsageAnomaliesJob — audit-event wiring
    // ------------------------------------------------------------

    public function test_the_job_records_an_anomaly_detected_audit_event_for_an_active_plaid_connection_with_a_spike(): void
    {
        $firm = $this->firmDurableForAudit();
        $connection = $this->connection($firm);
        $windowEnd = now();

        for ($day = 2; $day <= 29; $day++) {
            $this->recordUsage($firm, $connection, $windowEnd->clone()->subDays($day), 1);
        }
        $this->recordUsage($firm, $connection, $windowEnd->clone()->subHours(2), 20);

        (new DetectProviderUsageAnomaliesJob())->handle(
            $this->service,
            app(\App\Services\TimelineEventRecorder::class),
        );

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.anomaly_detected')
            ->first());

        $this->assertNotNull($event);
    }

    public function test_the_job_records_no_event_for_a_firm_with_no_active_plaid_connection(): void
    {
        $firm = Firm::factory()->create();

        (new DetectProviderUsageAnomaliesJob())->handle(
            $this->service,
            app(\App\Services\TimelineEventRecorder::class),
        );

        $eventCount = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.anomaly_detected')
            ->count());

        $this->assertSame(0, $eventCount);
    }
}
