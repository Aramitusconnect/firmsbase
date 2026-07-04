<?php

namespace App\Enums;

/**
 * IncidentStatus — incident_events.status. No exact value list given
 * by the PDF — recommendation using the standard 4-stage incident
 * lifecycle (matches common status-page conventions). Carried on
 * every event row for a given correlation_id; the current status is
 * always "the latest row's value."
 */
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';
}
