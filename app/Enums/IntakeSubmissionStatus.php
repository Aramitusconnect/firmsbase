<?php

namespace App\Enums;

/**
 * IntakeSubmissionStatus — intake_submissions.status. Not given exact
 * values by the master plan (proposed/approved during Phase 2
 * planning).
 */
enum IntakeSubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';
}
