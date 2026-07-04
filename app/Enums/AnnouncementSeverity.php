<?php

namespace App\Enums;

/**
 * AnnouncementSeverity — used twice on the announcements table: as the
 * announcement's own `severity`, and as an optional `min_severity`
 * targeting/filter threshold (an announcement is only relevant to a
 * viewer whose configured minimum severity is <= the announcement's
 * severity). Case order below is significant — AnnouncementService
 * compares cases by declaration order to evaluate the min_severity
 * threshold.
 */
enum AnnouncementSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
