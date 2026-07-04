<?php

namespace App\Services;

use App\Enums\DemoEventStatus;
use App\Enums\OpportunityStatus;
use App\Models\DemoEvent;
use App\Models\Opportunity;
use App\Models\PlatformAdmin;

class DemoEventService
{
    public function schedule(Opportunity $opportunity, \DateTimeInterface $scheduledAt): DemoEvent
    {
        $demoEvent = DemoEvent::create([
            'opportunity_id' => $opportunity->id,
            'scheduled_at' => $scheduledAt,
            'status' => DemoEventStatus::Scheduled,
        ]);

        $opportunity->update(['status' => OpportunityStatus::DemoScheduled]);

        return $demoEvent;
    }

    public function markHeld(DemoEvent $demoEvent, ?PlatformAdmin $conductedBy = null, ?string $notes = null): DemoEvent
    {
        $demoEvent->update([
            'status' => DemoEventStatus::Completed,
            'held_at' => now(),
            'conducted_by' => $conductedBy?->id,
            'notes' => $notes,
        ]);

        return $demoEvent->fresh();
    }

    public function markNoShow(DemoEvent $demoEvent): DemoEvent
    {
        $demoEvent->update(['status' => DemoEventStatus::NoShow]);

        return $demoEvent->fresh();
    }

    public function cancel(DemoEvent $demoEvent): DemoEvent
    {
        $demoEvent->update(['status' => DemoEventStatus::Cancelled]);

        return $demoEvent->fresh();
    }
}
