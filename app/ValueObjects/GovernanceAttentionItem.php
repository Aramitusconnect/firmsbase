<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * GovernanceAttentionItem — one condition on the Governance Overview's
 * "Requires Attention" list.
 *
 * Every item must correspond to a condition that was actually
 * evaluated. The overview may only claim "no governance issues
 * currently require action" when the evaluated-conditions list is
 * non-empty and every one of them came back clear — an unevaluated
 * condition is reported as its own attention item (severity
 * UNEVALUATED) rather than silently omitted, because "we did not look"
 * and "we looked and it was fine" are not the same answer.
 */
final readonly class GovernanceAttentionItem
{
    public const SEVERITY_BLOCKER = 'blocker';

    public const SEVERITY_WARNING = 'warning';

    /**
     * The condition could not be evaluated at all. Deliberately its own
     * severity rather than being dropped from the list: an operator must
     * be able to see the difference between a clean check and a check
     * that never ran.
     */
    public const SEVERITY_UNEVALUATED = 'unevaluated';

    public function __construct(
        public string $condition,
        public string $severity,
        public string $detail,
        public ?int $count = null,
        public ?string $url = null,
    ) {}

    public static function blocker(string $condition, string $detail, ?int $count = null, ?string $url = null): self
    {
        return new self($condition, self::SEVERITY_BLOCKER, $detail, $count, $url);
    }

    public static function warning(string $condition, string $detail, ?int $count = null, ?string $url = null): self
    {
        return new self($condition, self::SEVERITY_WARNING, $detail, $count, $url);
    }

    public static function unevaluated(string $condition, string $detail): self
    {
        return new self($condition, self::SEVERITY_UNEVALUATED, $detail);
    }

    public function color(): string
    {
        return match ($this->severity) {
            self::SEVERITY_BLOCKER => 'danger',
            self::SEVERITY_WARNING => 'warning',
            default => 'gray',
        };
    }

    /**
     * Text label, never colour alone — the overview has to stay legible
     * to an operator who cannot distinguish the badge colours.
     */
    public function severityLabel(): string
    {
        return match ($this->severity) {
            self::SEVERITY_BLOCKER => 'Blocker',
            self::SEVERITY_WARNING => 'Warning',
            default => 'Not evaluated',
        };
    }
}
