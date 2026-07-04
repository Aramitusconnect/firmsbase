<?php

namespace App\Enums;

/**
 * StatusPageEventStatus — status_page_events.status. Represents
 * visibility/publication state, NOT the incident-progress category
 * (that's the plain-string event_type on the same table — e.g.
 * "investigating"/"identified"/"monitoring"/"resolved"/
 * "maintenance_scheduled" — approved clarification: keep event_type a
 * plain string here). No exact value list given by the PDF —
 * recommendation.
 */
enum StatusPageEventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
}
