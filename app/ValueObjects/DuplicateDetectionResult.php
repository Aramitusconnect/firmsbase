<?php

namespace App\ValueObjects;

/**
 * DuplicateDetectionResult — returned by
 * ImportDuplicateDetectionService::detect() for a single ImportRow.
 * matchedType/matchedId point at whatever the row appears to duplicate
 * — either an existing production record (Client/Contact/Matter/Party/
 * Document) or, for Invoice/PaymentPlan (per approved correction #5),
 * another ImportRow carrying the same source reference in its
 * raw_data/mapped_data — never a live invoices/payment_plans column,
 * since neither table carries an external_reference/idempotency
 * column.
 */
final readonly class DuplicateDetectionResult
{
    public function __construct(
        public bool $isDuplicate,
        public ?string $matchedType = null,
        public ?int $matchedId = null,
        public ?string $matchReason = null,
    ) {
    }

    public static function noMatch(): self
    {
        return new self(false);
    }

    public static function match(string $matchedType, int $matchedId, string $reason): self
    {
        return new self(true, $matchedType, $matchedId, $reason);
    }
}
