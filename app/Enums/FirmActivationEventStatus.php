<?php

namespace App\Enums;

/**
 * FirmActivationEventStatus — firm_activation_events.status. No exact
 * value list given by the master plan for Phase 5 (unlike Phase 3/4's
 * Section-33-sourced enums) — this is a recommendation covering every
 * outcome the audit trail needs: a check that passed, a check/item
 * that is blocking production-readiness, an item marked complete, and
 * an item explicitly waived. event_type (a plain string on the same
 * table) describes WHAT happened; this enum describes the OUTCOME.
 */
enum FirmActivationEventStatus: string
{
    case Passed = 'passed';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Waived = 'waived';
}
