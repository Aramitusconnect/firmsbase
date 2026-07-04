<?php

namespace App\Enums;

/**
 * PaymentPlanStatus — values taken verbatim from the master plan PDF,
 * Section 33, Payment plan row: "draft; active; paused; renegotiated;
 * completed; defaulted; cancelled". "Activation locks the schedule;
 * renegotiation supersedes with a new version and pauses dunning;
 * installments are paid by canonical payments only" (same PDF row).
 */
enum PaymentPlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Renegotiated = 'renegotiated';
    case Completed = 'completed';
    case Defaulted = 'defaulted';
    case Cancelled = 'cancelled';
}
