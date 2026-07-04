<?php

namespace App\Enums;

/**
 * IncidentSeverity — incident_events.severity. No exact value list
 * given by the PDF — recommendation using standard 4-level incident
 * severity naming. Carried on every event row for a given
 * correlation_id so the current severity is always "the latest row's
 * value" — no separate incidents parent table is needed (see this
 * table's migration doc comment).
 */
enum IncidentSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
