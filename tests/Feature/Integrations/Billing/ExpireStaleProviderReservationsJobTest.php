<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Jobs\ExpireStaleProviderReservationsJob;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ExpireStaleProviderReservationsJobTest — checkpoint4-design-cost-control.md
 * §3.3/§3.2. Proves the sweep correctly expires a stale `reserved` row
 * whose `expires_at` has passed (a crashed worker between reserve and
 * finalize), never touches a `reserved` row that has not yet expired,
 * and never touches a row that is already in a terminal state.
 */
class ExpireStaleProviderReservationsJobTest extends TestCase
{
    use RefreshDatabase;

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function reservationRow(Firm $firm, FirmIntegration $connection, string $status, \DateTimeInterface $expiresAt): ProviderBillableCallReservation
    {
        return $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create([
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
            'reserved_at' => now()->subMinutes(10),
            'expires_at' => $expiresAt,
            'finalized_at' => $status === ProviderBillableCallReservation::STATUS_RESERVED ? null : now(),
        ]));
    }

    public function test_a_stale_reserved_row_past_its_ttl_is_expired(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $stale = $this->reservationRow($firm, $connection, ProviderBillableCallReservation::STATUS_RESERVED, now()->subMinutes(5));

        (new ExpireStaleProviderReservationsJob())->handle();

        $fresh = $this->runWithFirmContext($firm, fn () => $stale->fresh());
        $this->assertSame(ProviderBillableCallReservation::STATUS_EXPIRED, $fresh->status);
        $this->assertNotNull($fresh->finalized_at);
    }

    public function test_a_reserved_row_not_yet_past_its_ttl_is_left_untouched(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $fresh = $this->reservationRow($firm, $connection, ProviderBillableCallReservation::STATUS_RESERVED, now()->addMinutes(5));

        (new ExpireStaleProviderReservationsJob())->handle();

        $refetched = $this->runWithFirmContext($firm, fn () => $fresh->fresh());
        $this->assertSame(ProviderBillableCallReservation::STATUS_RESERVED, $refetched->status);
        $this->assertNull($refetched->finalized_at);
    }

    public function test_an_already_finalized_row_is_never_touched_even_if_its_ttl_has_long_passed(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $finalized = $this->reservationRow($firm, $connection, ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, now()->subDays(2));
        $originalFinalizedAt = $this->runWithFirmContext($firm, fn () => $finalized->fresh()->finalized_at);

        (new ExpireStaleProviderReservationsJob())->handle();

        $refetched = $this->runWithFirmContext($firm, fn () => $finalized->fresh());
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $refetched->status);
        $this->assertTrue($originalFinalizedAt->equalTo($refetched->finalized_at));
    }

    public function test_a_stale_reservation_belonging_to_a_different_firm_is_correctly_expired_too(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = $this->connection($firmA);
        $connectionB = $this->connection($firmB);
        $staleA = $this->reservationRow($firmA, $connectionA, ProviderBillableCallReservation::STATUS_RESERVED, now()->subMinutes(5));
        $staleB = $this->reservationRow($firmB, $connectionB, ProviderBillableCallReservation::STATUS_RESERVED, now()->subMinutes(5));

        (new ExpireStaleProviderReservationsJob())->handle();

        $freshA = $this->runWithFirmContext($firmA, fn () => $staleA->fresh());
        $freshB = $this->runWithFirmContext($firmB, fn () => $staleB->fresh());
        $this->assertSame(ProviderBillableCallReservation::STATUS_EXPIRED, $freshA->status);
        $this->assertSame(ProviderBillableCallReservation::STATUS_EXPIRED, $freshB->status);
    }
}
