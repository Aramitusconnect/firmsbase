<?php

namespace App\Enums;

/**
 * AccountingPeriodStatus — Phase K. No "Open" case: an accounting
 * period only ever gets a row once it is CLOSED (or, after a
 * correction, Reopened then re-Closed again) — there is no need to
 * pre-create a row for every month a firm operates; the absence of a
 * Closed row for a given date range simply means that range is
 * (implicitly) open, which is the correct default and needs no
 * explicit state.
 */
enum AccountingPeriodStatus: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';
}
