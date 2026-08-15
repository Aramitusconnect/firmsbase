<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * GovernanceMetric — a single Governance Overview number together with
 * an explicit statement of whether that number is real.
 *
 * This type exists because the Governance console must never let "we
 * have no evidence" render as the digit 0. A retention sweep that has
 * never been observed is NOT "0 failed sweeps"; a legal hold domain
 * with no expiry concept is NOT "0 holds expiring soon". Both are
 * genuinely different from a real, measured zero, and an operator
 * making a compliance decision has to be able to tell them apart.
 *
 * Availability vocabulary:
 *   - AVAILABLE: $value is a real count produced by a real query.
 *   - NOT_MONITORED: the thing exists but nothing durable records it,
 *     so no count can be produced (e.g. retention sweep history, which
 *     is written to a flat log file only). $value is always null.
 *   - NOT_SUPPORTED: the domain has no such concept at all, so the
 *     question itself does not apply (e.g. legal hold expiry, which
 *     this schema does not model). $value is always null.
 */
final readonly class GovernanceMetric
{
    public const AVAILABLE = 'available';

    public const NOT_MONITORED = 'not_monitored';

    public const NOT_SUPPORTED = 'not_supported';

    private function __construct(
        public string $label,
        public ?int $value,
        public string $availability,
        public ?string $explanation = null,
    ) {}

    public static function available(string $label, int $value): self
    {
        return new self($label, $value, self::AVAILABLE);
    }

    /**
     * No durable evidence exists for this metric, so it cannot be
     * counted. $explanation must say where the evidence would have to
     * come from — an operator seeing "Not monitored" needs to know
     * whether that is a gap they can close.
     */
    public static function notMonitored(string $label, string $explanation): self
    {
        return new self($label, null, self::NOT_MONITORED, $explanation);
    }

    /**
     * The domain has no such concept. $explanation must state what the
     * domain does instead, so the absence does not read as a defect.
     */
    public static function notSupported(string $label, string $explanation): self
    {
        return new self($label, null, self::NOT_SUPPORTED, $explanation);
    }

    public function isAvailable(): bool
    {
        return $this->availability === self::AVAILABLE;
    }

    /**
     * The operator-facing value. Never returns "0" for a metric that was
     * not actually measured.
     */
    public function display(): string
    {
        return match ($this->availability) {
            self::AVAILABLE => (string) $this->value,
            self::NOT_MONITORED => 'Not monitored',
            default => 'Not supported',
        };
    }
}
