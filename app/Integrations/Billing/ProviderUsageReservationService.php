<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Data\SanitizedUsageMetadataReference;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderRateCardEntry;
use App\Integrations\Services\IntegrationUsageRecorderService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ProviderUsageReservationService — pipeline steps 12 and 15
 * (checkpoint4-design-cost-control.md §2 steps 12/15, §3). `reserve()`
 * inserts a `reserved` row, idempotently (mirrors
 * `IntegrationUsageRecorderService::recordOnce()`'s exact
 * `insertOrIgnoreReturning()` + re-SELECT-fallback discipline — a
 * retried `reserve()` call with the same idempotency key returns the
 * existing row rather than erroring or double-reserving).
 * `estimated_customer_price_cents`/`rate_card_entry_id` are SNAPSHOTTED
 * here, never re-resolved at finalize time (design §3.4).
 *
 * `finalize()` transitions the reservation per
 * `ProviderCallOutcomeNormalizer`'s outcome, and — ONLY for a
 * `certain + billable` outcome — calls the EXISTING, UNMODIFIED
 * `IntegrationUsageRecorderService::recordOnce()` to write the real
 * `integration_usage_records` row, storing its id back onto the
 * reservation via `usage_record_id`.
 */
final class ProviderUsageReservationService
{
    public function __construct(private readonly IntegrationUsageRecorderService $usageRecorder) {}

    public function reserve(
        Firm $firm,
        FirmIntegration $connection,
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        string $environment,
        ?ProviderRateCardEntry $rate,
        string $idempotencyKey,
        int $quantity,
        int $reservationTtlSeconds,
        ?FirmUser $reservedBy = null,
        ?string $correlationId = null,
        ?string $reservationReason = null,
    ): ProviderBillableCallReservation {
        return (new TenantContextService())->runWithFirmContext($firm, function () use (
            $firm, $connection, $providerKey, $classification, $environment, $rate, $idempotencyKey,
            $quantity, $reservationTtlSeconds, $reservedBy, $correlationId, $reservationReason,
        ) {
            $now = now();

            $rows = DB::table('provider_billable_call_reservations')->insertOrIgnoreReturning(
                [
                    'uuid' => (string) Str::uuid7(),
                    'firm_id' => $firm->id,
                    'firm_integration_id' => $connection->id,
                    'provider_key' => $providerKey->value,
                    'product' => $classification->product,
                    'billing_operation' => $classification->billingOperation,
                    'environment' => $environment,
                    'rate_card_entry_id' => $rate?->id,
                    'estimated_customer_price_cents' => $rate?->customer_price_cents,
                    'quantity' => $quantity,
                    'unit' => $rate?->unit ?? 'request',
                    'status' => ProviderBillableCallReservation::STATUS_RESERVED,
                    'idempotency_key' => $idempotencyKey,
                    'correlation_id' => $correlationId,
                    'reserved_at' => $now,
                    'expires_at' => $now->clone()->addSeconds($reservationTtlSeconds),
                    'reserved_by_firm_user_id' => $reservedBy?->id,
                    'reservation_reason' => $reservationReason,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                returning: ['*'],
                uniqueBy: ['firm_integration_id', 'idempotency_key'],
            );

            $row = $rows->first() ?? DB::table('provider_billable_call_reservations')
                ->where('firm_integration_id', $connection->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            return ProviderBillableCallReservation::hydrate([(array) $row])->first();
        });
    }

    public function finalize(
        Firm $firm,
        ProviderBillableCallReservation $reservation,
        ProviderNormalizedOutcome $outcome,
        SyncDirection $direction,
        ?ResourceType $resourceType,
        int $usageQuantity = 1,
    ): ProviderBillableCallReservation {
        return (new TenantContextService())->runWithFirmContext($firm, function () use (
            $firm, $reservation, $outcome, $direction, $resourceType, $usageQuantity,
        ) {
            $status = match (true) {
                ! $outcome->certain => ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN,
                $outcome->billable => ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE,
                default => ProviderBillableCallReservation::STATUS_FINALIZED_NON_BILLABLE,
            };

            $usageRecordId = null;

            if ($status === ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE) {
                $usageRecord = $this->usageRecorder->recordOnce(
                    firmId: $firm->id,
                    firmIntegrationId: (int) $reservation->firm_integration_id,
                    providerKey: $reservation->provider_key,
                    capability: "{$reservation->product}:{$reservation->billing_operation}",
                    operationType: 'pull',
                    direction: $direction,
                    resourceType: $resourceType,
                    unit: $reservation->unit,
                    outcome: 'success',
                    idempotencyKey: $reservation->idempotency_key,
                    quantity: $usageQuantity,
                    metadata: new SanitizedUsageMetadataReference([
                        'billing_product' => $reservation->product,
                        'billing_operation' => $reservation->billing_operation,
                    ]),
                    correlationId: $reservation->correlation_id,
                );

                $usageRecordId = $usageRecord->id;
            }

            $reservation->forceFill([
                'status' => $status,
                'finalized_at' => now(),
                'usage_record_id' => $usageRecordId,
            ])->save();

            return $reservation->fresh();
        });
    }

    public function expire(ProviderBillableCallReservation $reservation): void
    {
        $reservation->forceFill([
            'status' => ProviderBillableCallReservation::STATUS_EXPIRED,
            'finalized_at' => now(),
        ])->save();
    }
}
