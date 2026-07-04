<?php

namespace App\Services;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\PilotFeedbackItem;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * PilotFeedbackService — the only place pilot_feedback_items rows are
 * created or transitioned.
 *
 * hotfix 01: scheduleFollowUp() normalizes follow_up_at to whole-second
 * precision (startOfSecond()) before persisting, for the same reason
 * MaintenanceWindowService normalizes its scheduled dates — a
 * \DateTimeInterface value with microsecond precision is not
 * guaranteed to compare equal to what actually round-trips through the
 * database column.
 */
class PilotFeedbackService
{
    public function submit(
        PilotFeedbackSource $source,
        PilotFeedbackCategory $category,
        string $title,
        string $description,
        ?Firm $firm = null,
        ?Client $client = null,
        ?Matter $matter = null,
        ?User $user = null,
        PilotFeedbackPriority $priority = PilotFeedbackPriority::Medium,
        ?User $createdBy = null,
    ): PilotFeedbackItem {
        return PilotFeedbackItem::create([
            'firm_id' => $firm?->id,
            'client_id' => $client?->id,
            'matter_id' => $matter?->id,
            'user_id' => $user?->id,
            'source' => $source,
            'category' => $category,
            'priority' => $priority,
            'status' => PilotFeedbackStatus::New,
            'title' => $title,
            'description' => $description,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function triage(PilotFeedbackItem $item, PilotFeedbackPriority $priority): PilotFeedbackItem
    {
        $item->update(['status' => PilotFeedbackStatus::Triaged, 'priority' => $priority]);

        return $item->fresh();
    }

    public function startProgress(PilotFeedbackItem $item): PilotFeedbackItem
    {
        $item->update(['status' => PilotFeedbackStatus::InProgress]);

        return $item->fresh();
    }

    public function resolve(PilotFeedbackItem $item, string $resolutionNotes): PilotFeedbackItem
    {
        $item->update([
            'status' => PilotFeedbackStatus::Resolved,
            'resolution_notes' => $resolutionNotes,
            'resolved_at' => now(),
        ]);

        return $item->fresh();
    }

    public function markWontFix(PilotFeedbackItem $item, string $reason): PilotFeedbackItem
    {
        $item->update(['status' => PilotFeedbackStatus::WontFix, 'resolution_notes' => $reason]);

        return $item->fresh();
    }

    public function markDuplicate(PilotFeedbackItem $item): PilotFeedbackItem
    {
        $item->update(['status' => PilotFeedbackStatus::Duplicate]);

        return $item->fresh();
    }

    public function scheduleFollowUp(PilotFeedbackItem $item, \DateTimeInterface $followUpAt): PilotFeedbackItem
    {
        $item->update([
            'follow_up_required' => true,
            'follow_up_at' => Carbon::instance($followUpAt)->startOfSecond(),
        ]);

        return $item->fresh();
    }
}
