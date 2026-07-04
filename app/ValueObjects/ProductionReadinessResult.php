<?php

namespace App\ValueObjects;

/**
 * ProductionReadinessResult — returned by
 * FirmProductionActivationService::evaluate(). Purely a read-time
 * computation over firm_activation_events + the existing Phase 1
 * activation/checklist/license records (project rule: event-derived
 * only, no new firms column) — this value object is never itself
 * persisted.
 */
final readonly class ProductionReadinessResult
{
    /**
     * @param  array<int, string>  $unmetItems  checklist item_keys still incomplete/unwaived
     * @param  array<int, string>  $blockingReasons  human-readable blocking reasons beyond the checklist (e.g. missing license)
     */
    public function __construct(
        public bool $ready,
        public array $unmetItems = [],
        public array $blockingReasons = [],
    ) {
    }
}
