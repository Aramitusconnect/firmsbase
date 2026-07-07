<?php

namespace App\ValueObjects;

use Carbon\CarbonInterface;

/**
 * CommandCenterSnapshot — one read-only aggregation result returned by
 * FirmCommandCenterAggregationService::snapshot(). Not persisted
 * anywhere (no command-center table exists or is created) and carries
 * no behavior of its own — pure data produced fresh from real,
 * firm-scoped queries every time it is requested.
 */
final readonly class CommandCenterSnapshot
{
    public function __construct(
        public int $newLeadsCount,
        public int $consultationsCount,
        public int $mattersWaitingOnClientCount,
        public int $mattersReadyForReviewCount,
        public int $documentsNeedingApprovalCount,
        public int $deadlinesThisWeekCount,
        public int $unpaidInvoicesCount,
        public int $installmentsDueCount,
        public int $installmentsMissedCount,
        public int $failedPaymentsCount,
        public int $inactiveClientsCount,
        public int $overdueTasksCount,
        public int $blockedTasksCount,
        public int $formsReadyForReviewCount,
        public int $documentChaseEscalationsCount,
        public CarbonInterface $generatedAt,
    ) {
    }
}
