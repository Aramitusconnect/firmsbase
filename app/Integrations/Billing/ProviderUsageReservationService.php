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
 *
 * Double-billing remediation additions (all additive; `reserve()`'s and
 * `finalize()`'s existing behaviour is unchanged):
 *   - `reserve()` now reports WHICH branch produced the row via the
 *     returned model's `wasRecentlyCreated` flag. Idempotent bookkeeping
 *     of the ledger row was always correct here; what was missing was any
 *     way for the pipeline to know it had been handed someone else's
 *     in-flight/already-finalized reservation rather than a fresh one.
 *   - `markProviderCallStarted()` durably records outbound-call intent
 *     before the call leaves the process.
 *   - `reclaim()` is the single-winner compare-and-set that lets a
 *     provably-safe re-attempt reuse the existing row (the deterministic
 *     idempotency key + unique index make inserting a second row
 *     impossible by design).
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
        return (new TenantContextService)->runWithFirmContext($firm, function () use (
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

            // Which of the two branches produced the row is the single
            // most safety-relevant fact this method knows, and until the
            // Critical double-billing remediation it was thrown away
            // here: an INSERTed row means THIS attempt owns a brand-new
            // reservation, whereas a SELECT-fallback row means some
            // EARLIER attempt already reserved this exact logical
            // operation and may already have called (and been billed by)
            // the provider. Surfaced on the returned model through
            // Eloquent's own existing `wasRecentlyCreated` semantics
            // rather than a bespoke second return type, so every existing
            // caller keeps compiling unchanged and
            // `ProviderBillableCallPipeline` can gate step 13 on it.
            $insertedRow = $rows->first();

            $row = $insertedRow ?? DB::table('provider_billable_call_reservations')
                ->where('firm_integration_id', $connection->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            $reservation = ProviderBillableCallReservation::hydrate([(array) $row])->first();
            $reservation->wasRecentlyCreated = $insertedRow !== null;

            return $reservation;
        });
    }

    /**
     * Records — durably, BEFORE `$providerCall()` runs — that this
     * reservation's outbound call is about to leave the process. See the
     * `provider_call_started_at` migration's docblock: this write is what
     * lets a LATER attempt that finds an abandoned reservation tell
     * "crashed before the provider was ever contacted" (safe to
     * re-attempt) from "crashed with a call in flight" (genuinely
     * ambiguous, never auto-retried).
     */
    public function markProviderCallStarted(Firm $firm, ProviderBillableCallReservation $reservation): ProviderBillableCallReservation
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($reservation) {
            $now = now();

            DB::table('provider_billable_call_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'provider_call_started_at' => $now,
                    'updated_at' => $now,
                ]);

            $reservation->forceFill(['provider_call_started_at' => $now])->syncOriginal();

            return $reservation;
        });
    }

    /**
     * Atomically re-claims a reservation row that no live attempt can
     * still own, resetting it to a fresh `reserved` lease for the caller.
     *
     * Required because the idempotency key is now DETERMINISTIC: the
     * `(firm_integration_id, idempotency_key)` unique index means a
     * legitimate re-attempt of the same logical operation cannot insert a
     * second row, so the only way to re-attempt at all is to re-claim the
     * existing one. Eligibility (which statuses may be re-claimed, and
     * whether `provider_call_started_at` forbids it) is decided by
     * `ProviderBillableCallPipeline` — this method only guarantees that
     * AT MOST ONE concurrent caller wins.
     *
     * The compare-and-set predicate is the caller's OBSERVED status (plus,
     * for a stale `reserved` row, `expires_at < now`), so the first
     * UPDATE to commit invalidates every competing UPDATE's predicate
     * under READ COMMITTED. A loser gets null and must not proceed.
     *
     * @return ProviderBillableCallReservation|null null when another
     *                                              worker won the race
     */
    public function reclaim(
        Firm $firm,
        ProviderBillableCallReservation $reservation,
        int $reservationTtlSeconds,
    ): ?ProviderBillableCallReservation {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($reservation, $reservationTtlSeconds) {
            $now = now();
            $observedStatus = (string) $reservation->status;

            $query = DB::table('provider_billable_call_reservations')
                ->where('id', $reservation->id)
                ->where('status', $observedStatus);

            if ($observedStatus === ProviderBillableCallReservation::STATUS_RESERVED) {
                // A `reserved` row is only re-claimable BECAUSE it is
                // past its TTL; re-asserting that in the predicate is
                // what makes this branch single-winner (the winner
                // pushes expires_at into the future, so every loser's
                // predicate stops matching).
                $query->where('expires_at', '<', $now);
            }

            $affected = $query->update([
                'status' => ProviderBillableCallReservation::STATUS_RESERVED,
                'reserved_at' => $now,
                'expires_at' => $now->clone()->addSeconds($reservationTtlSeconds),
                'finalized_at' => null,
                'provider_call_started_at' => null,
                'usage_record_id' => null,
                'updated_at' => $now,
            ]);

            if ($affected !== 1) {
                return null;
            }

            return $reservation->fresh();
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
        return (new TenantContextService)->runWithFirmContext($firm, function () use (
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
