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
 *
 * Section 39A-3K — calendar_events now has FORCE ROW LEVEL SECURITY
 * active. Tenant-context wiring: this service does NOT self-wrap in
 * runWithFirmContext() — its one production call site,
 * DeadlineService::create(), already wraps its whole operation
 * (including this call) in runWithFirmContext($firm, ...); nesting a
 * second wrap here would clear DeadlineService's own context before it
 * finishes (see project convention on nested wraps). createStandalone()
 * has no production caller today (see the batch report) — a future
 * caller must establish context itself before calling it, the same way
 * DeadlineService::create() does for createFor().
 *
 * Ownership-consistency guard: previously this service trusted
 * whatever $firm the caller passed with no cross-check against
 * $matter/$subject's own firm_id, even though both are tenant-owned
 * parents (Matter always; every real subject type used today —
 * Deadline — is BelongsToTenant too). assertBelongsToFirm() below
 * fails closed on a mismatch rather than silently writing a row whose
 * firm_id disagrees with its own matter/subject, mirroring the
 * root-cause fixes already applied to MatterFactory/PaymentFactory in
 * prior FORCE batches — this is a narrow consistency check, not a
 * change to any conflict-check/scheduling/notification business logic.
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
        $this->assertBelongsToFirm($firm, $subject, 'subject');
        $this->assertBelongsToFirm($firm, $matter, 'matter');

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
        $this->assertBelongsToFirm($firm, $matter, 'matter');

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

    /**
     * Fails closed rather than silently writing a cross-firm-mismatched
     * row: only checks models that actually expose firm_id (every
     * tenant-owned model does; a non-tenant-owned subject, if one is
     * ever introduced, is simply not checked here).
     */
    private function assertBelongsToFirm(Firm $firm, ?Model $related, string $label): void
    {
        if ($related === null || ! isset($related->firm_id)) {
            return;
        }

        if ((int) $related->firm_id !== $firm->id) {
            throw new \RuntimeException(
                "Refusing to create a calendar event: the given {$label} belongs to firm {$related->firm_id}, not firm {$firm->id}."
            );
        }
    }
}
