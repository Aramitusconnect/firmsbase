<?php

namespace App\Services;

use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * CalendarEventService — the only place calendar_events rows are
 * created. createFor() is used by DeadlineService/TaskService to
 * auto-create a linked entry (subject_type/subject_id); createStandalone()
 * is for a bare staff-created event with no linked subject (e.g. "client
 * meeting"). No external Google/Outlook sync (out of phase).
 */
class CalendarEventService
{
    public function createFor(
        Firm $firm,
        Model $subject,
        CalendarEventType $eventType,
        string $title,
        \DateTimeInterface $startsAt,
        ?\DateTimeInterface $endsAt = null,
        ?Matter $matter = null,
        bool $allDay = false,
        ?User $createdBy = null,
    ): CalendarEvent {
        return CalendarEvent::create([
            'firm_id' => $firm->id,
            'matter_id' => $matter?->id,
            'event_type' => $eventType,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function createStandalone(
        Firm $firm,
        string $title,
        \DateTimeInterface $startsAt,
        ?\DateTimeInterface $endsAt = null,
        ?Matter $matter = null,
        bool $allDay = false,
        ?User $createdBy = null,
    ): CalendarEvent {
        return CalendarEvent::create([
            'firm_id' => $firm->id,
            'matter_id' => $matter?->id,
            'event_type' => CalendarEventType::Standalone,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'created_by' => $createdBy?->id,
        ]);
    }
}
