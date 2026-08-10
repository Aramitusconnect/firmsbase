<?php

namespace App\Console\Commands;

use App\Enums\DeadlineStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Models\Deadline;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DeadlineService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * automation:sweep:deadlines — Event-Driven Automation Engine, item
 * 3/13. Same "no real mutation call site to hook into, so sweep and
 * emit" rationale as SweepInvoiceOverdueEventsCommand, reusing
 * DeadlineService::reminderDates()/refreshMissedStatus() unmodified —
 * both already existed, fully built, simply never called by anything
 * in production (confirmed by this pass's own audit).
 *
 * DeadlineMissed uses refreshMissedStatus()'s own real status mutation
 * as the trigger (the moment a Deadline actually transitions to
 * Missed). DeadlineApproaching fires the first time "now" passes any
 * one of the deadline's own reminder_offsets_days dates — exactly once
 * ever per deadline (a dedup existence check against domain_events,
 * same shape as the invoice-overdue sweep), never once per offset and
 * never re-fired on every subsequent daily run.
 */
final class SweepDeadlineEventsCommand extends Command
{
    protected $signature = 'automation:sweep:deadlines';

    protected $description = 'Emits DeadlineApproaching/DeadlineMissed domain events for upcoming deadlines, for every activated firm.';

    public function __construct(
        private readonly DeadlineService $deadlines,
        private readonly DomainEventRecorderService $domainEvents,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->cursor()
            ->each(fn (Firm $firm) => $this->sweepFirm($firm));

        return self::SUCCESS;
    }

    private function sweepFirm(Firm $firm): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            $upcoming = Deadline::query()
                ->where('firm_id', $firm->id)
                ->where('status', DeadlineStatus::Upcoming)
                ->get();

            foreach ($upcoming as $deadline) {
                $this->sweepDeadline($firm, $deadline);
            }
        });
    }

    private function sweepDeadline(Firm $firm, Deadline $deadline): void
    {
        $refreshed = $this->deadlines->refreshMissedStatus($deadline);

        if ($refreshed->status === DeadlineStatus::Missed) {
            $this->emitOnce($firm, DomainEventType::DeadlineMissed, $refreshed);

            return;
        }

        $reminderDates = $this->deadlines->reminderDates($refreshed);
        $anyReminderDue = collect($reminderDates)->contains(fn ($date) => $date->isPast());

        if ($anyReminderDue) {
            $this->emitOnce($firm, DomainEventType::DeadlineApproaching, $refreshed);
        }
    }

    private function emitOnce(Firm $firm, DomainEventType $type, Deadline $deadline): void
    {
        $alreadyEmitted = DomainEvent::query()
            ->where('subject_type', $deadline->getMorphClass())
            ->where('subject_id', $deadline->id)
            ->where('event_type', $type->value)
            ->exists();

        if ($alreadyEmitted) {
            return;
        }

        $this->domainEvents->record($firm, $type, [
            'deadline' => [
                'id' => $deadline->id,
                'title' => $deadline->title,
                'deadline_type' => $deadline->deadline_type,
                'due_at' => $deadline->due_at->toIso8601String(),
                'days_until_due' => round(now()->diffInHours($deadline->due_at, false) / 24, 2),
            ],
            'matter' => [
                'id' => $deadline->matter_id,
                'assigned_attorney_id' => $deadline->matter?->assigned_attorney_id,
            ],
        ], subject: $deadline);
    }
}
