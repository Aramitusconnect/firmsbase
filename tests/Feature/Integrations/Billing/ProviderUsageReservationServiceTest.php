<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderBillingClassifier;
use App\Integrations\Billing\ProviderCallOutcomeNormalizer;
use App\Integrations\Billing\ProviderUsageReservationService;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderRateCardEntry;
use App\Integrations\Services\IntegrationUsageRecorderService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ProviderUsageReservationServiceTest — checkpoint4-design-cost-control.md
 * §2 steps 12/15, §3. Proves the two-phase reserve -> finalize state
 * machine reaches the correct terminal state
 * (finalized_billable/finalized_non_billable/finalized_uncertain) from
 * the right ProviderNormalizedOutcome, that reserve() is idempotent on
 * a repeated idempotency key, that the rate is snapshotted at
 * reservation time (never re-resolved at finalize), and that only a
 * certain+billable finalize writes a real integration_usage_records row.
 */
class ProviderUsageReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProviderUsageReservationService $reservations;

    private ProviderBillingClassifier $classifier;

    private ProviderCallOutcomeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservations = new ProviderUsageReservationService(new IntegrationUsageRecorderService());
        $this->classifier = new ProviderBillingClassifier();
        $this->normalizer = new ProviderCallOutcomeNormalizer();
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function rateCard(): ProviderRateCardEntry
    {
        return ProviderRateCardEntry::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'billing_operation' => 'download',
            'environment' => 'production',
            'scope_type' => 'platform_default',
            'customer_price_cents' => 75,
            'unit' => 'request',
            'effective_from' => now()->subYear(),
        ]);
    }

    public function test_reserve_creates_a_reserved_row_snapshotting_the_rate(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $rate = $this->rateCard();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $reservation = $this->reservations->reserve(
            firm: $firm,
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            classification: $classification,
            environment: 'production',
            rate: $rate,
            idempotencyKey: 'plaid_statement:'.Str::uuid7(),
            quantity: 1,
            reservationTtlSeconds: 120,
        );

        $this->assertSame(ProviderBillableCallReservation::STATUS_RESERVED, $reservation->status);
        $this->assertSame($rate->id, $reservation->rate_card_entry_id);
        $this->assertSame(75, $reservation->estimated_customer_price_cents);
    }

    public function test_reserve_is_idempotent_on_a_repeated_idempotency_key(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $key = 'plaid_statement:'.Str::uuid7();

        $first = $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', null, $key, 1, 120);
        $second = $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', null, $key, 1, 120);

        $this->assertSame($first->id, $second->id);

        $count = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('idempotency_key', $key)->count());
        $this->assertSame(1, $count);
    }

    public function test_a_rate_change_after_reservation_never_affects_the_already_snapshotted_price(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $rate = $this->rateCard();
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');

        $reservation = $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', $rate, 'k:'.Str::uuid7(), 1, 120);

        // Platform admin changes the rate after reservation.
        $rate->update(['customer_price_cents' => 5000]);

        $freshPrice = $this->runWithFirmContext($firm, fn () => $reservation->fresh()->estimated_customer_price_cents);
        $this->assertSame(75, $freshPrice);
    }

    public function test_finalize_certain_billable_transitions_to_finalized_billable_and_writes_a_usage_record(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $reservation = $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', null, 'k:'.Str::uuid7(), 1, 120);

        $outcome = $this->normalizer->normalize(['ok' => true], null);
        $finalized = $this->reservations->finalize($firm, $reservation, $outcome, SyncDirection::Inbound, ResourceType::Statement);

        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $finalized->status);
        $this->assertNotNull($finalized->finalized_at);
        $this->assertNotNull($finalized->usage_record_id);

        $usageRowExists = $this->runWithFirmContext($firm, fn () => \App\Integrations\Models\IntegrationUsageRecord::query()
            ->where('id', $finalized->usage_record_id)->exists());
        $this->assertTrue($usageRowExists);
    }

    public function test_finalize_certain_non_billable_transitions_to_finalized_non_billable_and_writes_no_usage_record(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $reservation = $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', null, 'k:'.Str::uuid7(), 1, 120);

        $exception = new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED, 422, 'downloadStatement');
        $outcome = $this->normalizer->normalize(null, $exception);
        $finalized = $this->reservations->finalize($firm, $reservation, $outcome, SyncDirection::Inbound, ResourceType::Statement);

        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_NON_BILLABLE, $finalized->status);
        $this->assertNull($finalized->usage_record_id);
    }

    public function test_finalize_uncertain_transitions_to_finalized_uncertain_and_writes_no_usage_record(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $reservation = $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', null, 'k:'.Str::uuid7(), 1, 120);

        $exception = new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'downloadStatement');
        $outcome = $this->normalizer->normalize(null, $exception);
        $finalized = $this->reservations->finalize($firm, $reservation, $outcome, SyncDirection::Inbound, ResourceType::Statement);

        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN, $finalized->status);
        $this->assertNull($finalized->usage_record_id);
    }

    public function test_expire_transitions_a_reserved_row_to_expired(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $classification = $this->classifier->classify(ProviderKey::Plaid, 'statements', 'download');
        $reservation = $this->createWithFirmContext($firm, fn () => $this->reservations->reserve($firm, $connection, ProviderKey::Plaid, $classification, 'production', null, 'k:'.Str::uuid7(), 1, 120));

        $this->createWithFirmContext($firm, fn () => $this->reservations->expire($reservation));

        $fresh = $this->runWithFirmContext($firm, fn () => $reservation->fresh());
        $this->assertSame(ProviderBillableCallReservation::STATUS_EXPIRED, $fresh->status);
        $this->assertNotNull($fresh->finalized_at);
    }
}
