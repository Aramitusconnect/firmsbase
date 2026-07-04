<?php

namespace App\ValueObjects;

use App\Enums\CustomerHealthRiskLevel;

/**
 * CustomerSuccessSnapshot — a single point-in-time read of a firm's
 * customer-success signals, returned by CustomerSuccessConsoleService.
 * This is a safe SUMMARY/AGGREGATE view only: it carries counts, not
 * document content, matter content, or client PII beyond identifiers
 * already safe for platform-staff console use. It never exposes
 * document bodies, matter notes, or message contents.
 */
final readonly class CustomerSuccessSnapshot
{
    public function __construct(
        public int $firmId,
        public int $score,
        public CustomerHealthRiskLevel $riskLevel,
        public ?int $onboardingProgressPercent,
        public ?string $lastLoginAt,
        public ?int $activeUsersCount,
        public ?int $mattersCount,
        public ?int $clientsCount,
        public ?int $documentsCount,
        public ?int $invoicesCount,
        public ?int $paymentPlansCount,
        public ?int $paymentsCount,
        public ?int $aiUsageCount,
        public ?int $storageBytes,
        public ?int $failedJobsCount,
        public ?int $openTicketsCount,
        public ?string $subscriptionStatus,
        public array $riskFlags,
    ) {
    }
}
