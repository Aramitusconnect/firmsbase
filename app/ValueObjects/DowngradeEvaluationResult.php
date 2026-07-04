<?php

namespace App\ValueObjects;

use App\Enums\DowngradeCheckStatus;

/**
 * DowngradeEvaluationResult — the result of
 * DowngradeEvaluationService::evaluate(). Mirrors Phase 5's
 * ProductionReadinessResult pattern: computed at read time, never
 * persisted as its own row. "safe" means the downgrade may proceed
 * without further admin action; a non-safe result never means the
 * firm is locked out of its legal data — see PastDueBillingPolicyService/
 * LegalDataAccessPolicyService for that separate concern (project rule:
 * "past-due or suspended firms must not be abruptly locked out of
 * legal data").
 */
final readonly class DowngradeEvaluationResult
{
    public function __construct(
        public bool $safe,
        public DowngradeCheckStatus $status,
        public array $blockingReasons,
        public array $seatFindings,
    ) {
    }

    public static function safe(): self
    {
        return new self(safe: true, status: DowngradeCheckStatus::Safe, blockingReasons: [], seatFindings: []);
    }

    public static function blocked(DowngradeCheckStatus $status, array $blockingReasons, array $seatFindings = []): self
    {
        return new self(safe: false, status: $status, blockingReasons: $blockingReasons, seatFindings: $seatFindings);
    }
}
