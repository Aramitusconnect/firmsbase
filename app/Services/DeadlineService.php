<?php

namespace App\Services;

use App\Enums\CalendarEventType;
use App\Enums\DeadlineStatus;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * DeadlineService — the only place a Deadline is created or
 * transitioned. Automatically creates a matching CalendarEvent
 * (event_type=Deadline, subject=this Deadline) so "Deadlines can
 * create reminders. Calendar events can represent deadline/reminder/
 * matter activity" (project rule) is true by construction, not by a
 * separate manual step.
 */
class DeadlineService
{
    public function __construct(private CalendarEventService $calendarEvents)
    {
    }

    /**
     * @param  array<int, int>|null  $reminderOffsetsDays  e.g. [7,3,1]
     */
    public function create(
        Firm $firm,
        string $title,
        string $deadlineType,
        \DateTimeInterface $dueAt,
        ?Matter $matter = null,
        ?string $jurisdiction = null,
        ?string $source = null,
        ?array $reminderOffsetsDays = null,
        ?User $createdBy = null,
    ): Deadline {
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $matter, $title, $deadlineType, $dueAt, $jurisdiction, $source, $reminderOffsetsDays, $createdBy) {
            $deadline = Deadline::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter?->id,
                'title' => $title,
                'deadline_type' => $deadlineType,
                'due_at' => $dueAt,
                'jurisdiction' => $jurisdiction,
                'source' => $source,
                'reminder_offsets_days' => $reminderOffsetsDays,
                'status' => DeadlineStatus::Upcoming,
                'created_by' => $createdBy?->id,
            ]);

            $this->calendarEvents->createFor($firm, $deadline, CalendarEventType::Deadline, $title, $dueAt, matter: $matter, createdBy: $createdBy);

            return $deadline->fresh();
        });
    }

    public function complete(Deadline $deadline): Deadline
    {
        return (new TenantContextService())->runWithFirmContext($deadline->firm_id, function () use ($deadline) {
            $deadline->update(['status' => DeadlineStatus::Completed, 'completed_at' => now()]);

            return $deadline->fresh();
        });
    }

    public function cancel(Deadline $deadline): Deadline
    {
        return (new TenantContextService())->runWithFirmContext($deadline->firm_id, function () use ($deadline) {
            $deadline->update(['status' => DeadlineStatus::Cancelled, 'cancelled_at' => now()]);

            return $deadline->fresh();
        });
    }

    /**
     * Derives Missed from due_at rather than accepting it as directly
     * settable, same discipline as TaskService::refreshOverdueStatus().
     */
    public function refreshMissedStatus(Deadline $deadline): Deadline
    {
        if ($deadline->status !== DeadlineStatus::Upcoming) {
            return $deadline;
        }

        return (new TenantContextService())->runWithFirmContext($deadline->firm_id, function () use ($deadline) {
            if ($deadline->due_at->isPast()) {
                $deadline->update(['status' => DeadlineStatus::Missed]);
            }

            return $deadline->fresh();
        });
    }

    /**
     * The reminder due-dates implied by reminder_offsets_days, e.g.
     * due_at minus 7/3/1 days. Pure calculation — does not dispatch
     * anything; a caller wires this into NotificationDispatchService.
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public function reminderDates(Deadline $deadline): array
    {
        $offsets = $deadline->reminder_offsets_days ?? [];

        return array_map(fn (int $days) => $deadline->due_at->copy()->subDays($days), $offsets);
    }
}
