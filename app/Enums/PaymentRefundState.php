<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PaymentRefundState — FirmsVault Pay Gate A2, Master Execution Prompt
 * v1.4 §27. Only the states actually required; no speculative additions.
 *
 * The reservation is the money-safety device. From Reserved onward the
 * refund HOLDS refundable capacity on its parent PaymentAttempt, and
 * that hold is what stops two concurrent workers refunding the same
 * money (§26). The hold is released ONLY by a state that proves the
 * money was not (and will never be) taken by this refund:
 * ProviderFailed, ReservationExpired, Cancelled.
 *
 * OutcomeUnknown DELIBERATELY KEEPS THE RESERVATION HELD (§28). A
 * timeout must never release capacity and let a second refund go out
 * while the first one's fate is unresolved — that is precisely how a
 * double refund happens.
 */
enum PaymentRefundState: string
{
    case Requested = 'requested';
    case Reserved = 'reserved';
    case ProviderPending = 'provider_pending';
    case OutcomeUnknown = 'outcome_unknown';
    case Succeeded = 'succeeded';
    case ProviderFailed = 'provider_failed';
    case ReservationExpired = 'reservation_expired';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<string>>
     */
    public static function transitionMatrix(): array
    {
        return [
            self::Requested->value => [
                self::Reserved->value,
                self::Cancelled->value,
            ],
            self::Reserved->value => [
                self::ProviderPending->value,
                self::Cancelled->value,
                self::ReservationExpired->value,
            ],
            self::ProviderPending->value => [
                self::Succeeded->value,
                self::ProviderFailed->value,
                self::OutcomeUnknown->value,
            ],
            // Terminal for automated processing. A human/provider
            // reconciliation resolves THIS refund; it never spawns a
            // second provider refund command (§28).
            self::OutcomeUnknown->value => [],
            self::Succeeded->value => [],
            self::ProviderFailed->value => [],
            self::ReservationExpired->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next->value, self::transitionMatrix()[$this->value] ?? [], true);
    }

    /**
     * Whether a refund in this state currently consumes refundable
     * capacity on its parent attempt. This is the exact predicate the
     * database CHECK-backed reservation arithmetic uses, and the ONLY
     * definition of "active reservation" in the system.
     *
     * Succeeded counts because the money is genuinely gone.
     * OutcomeUnknown counts because the money MIGHT be gone, and
     * assuming otherwise is what causes a double refund.
     */
    public function holdsRefundableCapacity(): bool
    {
        return match ($this) {
            self::Reserved, self::ProviderPending, self::OutcomeUnknown, self::Succeeded => true,
            self::Requested, self::ProviderFailed, self::ReservationExpired, self::Cancelled => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function capacityHoldingValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->holdsRefundableCapacity()),
        ));
    }
}
