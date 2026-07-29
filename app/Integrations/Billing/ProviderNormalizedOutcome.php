<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

/**
 * ProviderNormalizedOutcome — `ProviderCallOutcomeNormalizer::normalize()`'s
 * return value (pipeline step 14, checkpoint4-design-cost-control.md §2
 * step 14/§3.2). Drives the reservation's finalize transition:
 * `billable && certain` -> `finalized_billable`; `!billable && certain`
 * -> `finalized_non_billable`; `!certain` -> `finalized_uncertain`
 * (billable is meaningless/ignored when uncertain).
 */
final class ProviderNormalizedOutcome
{
    public const CATEGORY_SERVED_FROM_CACHE = 'served_from_cache';

    public const CATEGORY_SERVED_FROM_EXISTING_RESERVATION = 'served_from_existing_reservation';

    public function __construct(
        public readonly bool $billable,
        public readonly bool $certain,
        public readonly string $category,
    ) {}

    public static function success(): self
    {
        return new self(billable: true, certain: true, category: 'success');
    }

    public static function nonBillable(string $category): self
    {
        return new self(billable: false, certain: true, category: $category);
    }

    public static function uncertain(string $category): self
    {
        return new self(billable: false, certain: false, category: $category);
    }

    /**
     * A cache hit (pipeline step 8) never reaches the real outcome
     * normalizer at all — this factory exists purely so
     * `ProviderBillableCallPipeline::execute()` can still return one
     * uniform `ProviderBillableCallResult` shape for a served-from-cache
     * response, without inventing a second return type.
     */
    public static function servedFromCache(): self
    {
        return new self(billable: false, certain: true, category: self::CATEGORY_SERVED_FROM_CACHE);
    }

    /**
     * The step-12b sibling of `servedFromCache()`: `reserve()` returned
     * an EXISTING reservation for this exact logical operation whose
     * recorded outcome forbids re-firing the real call (already
     * `finalized_billable`, already `finalized_uncertain`, or abandoned
     * with an outbound call provably already in flight). No new provider
     * call happened and no new usage record was written, so — exactly
     * like a cache hit — `billable` is false for THIS invocation.
     *
     * `ProviderBillableCallResult::$reservation` carries the existing
     * row, so a caller that needs the recorded outcome reads
     * `$result->reservation->status` (`finalized_billable` etc.) rather
     * than having a fresh, fabricated outcome invented for it here.
     * `$result->response` is null: a reservation records what the call
     * COST, never what it returned, so there is no honest way to replay
     * a prior attempt's response body.
     */
    public static function servedFromExistingReservation(): self
    {
        return new self(billable: false, certain: true, category: self::CATEGORY_SERVED_FROM_EXISTING_RESERVATION);
    }

    /**
     * True when this outcome represents a short-circuit that never
     * reached the provider at all — the one predicate a caller needs to
     * tell "`$response` is null because nothing was called" apart from
     * "`$response` is null because the provider returned null".
     */
    public function servedWithoutProviderCall(): bool
    {
        return in_array($this->category, [
            self::CATEGORY_SERVED_FROM_CACHE,
            self::CATEGORY_SERVED_FROM_EXISTING_RESERVATION,
        ], true);
    }
}
