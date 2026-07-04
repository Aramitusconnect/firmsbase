<?php

namespace App\Enums;

/**
 * TimeTrackingSessionStatus — small internal-only status enum, added
 * as a recommendation beyond your original enum list for the same
 * reason every other internal lifecycle table in this codebase
 * (ActivationChecklistStatus, ConflictCheckRunStatus, ...) uses a
 * closed enum rather than a bare string. Not part of any public
 * classification or billing-critical state machine.
 */
enum TimeTrackingSessionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Stopped = 'stopped';
}
