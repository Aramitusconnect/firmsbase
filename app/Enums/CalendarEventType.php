<?php

namespace App\Enums;

/**
 * CalendarEventType — calendar_events.event_type. No exact value list
 * given by the PDF; this set matches the Scope text verbatim:
 * "Calendar events can represent deadline/reminder/matter activity" —
 * plus Task, since tasks also carry a due_at worth representing.
 * subject_type/subject_id (a lightweight polymorphic reference, same
 * pattern as timeline_events.subject) point at the Deadline/Task/etc
 * this entry represents when auto-created; a standalone staff-created
 * event (e.g. "client meeting") has no subject.
 */
enum CalendarEventType: string
{
    case Deadline = 'deadline';
    case Reminder = 'reminder';
    case Task = 'task';
    case MatterActivity = 'matter_activity';
    case Standalone = 'standalone';
}
