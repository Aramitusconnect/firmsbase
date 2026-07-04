<?php

namespace App\Services;

use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * TimeEntryApprovalService — the only place a TimeEntry's status
 * transitions, and the only place a manual (non-timer) entry is
 * created. Approval snapshots the employee's CURRENT billing rate
 * (via EmployeeRateService) onto the entry — this is what makes a
 * later rate change never retroactively alter an already-approved
 * entry's price.
 */
class TimeEntryApprovalService
{
    public function __construct(private EmployeeRateService $rates)
    {
    }

    public function createManualEntry(
        Firm $firm,
        User $user,
        int $seconds,
        \DateTimeInterface $workedOn,
        ?Matter $matter = null,
        ?Client $client = null,
        bool $isBillable = true,
        ?string $description = null,
    ): TimeEntry {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('seconds must not be negative.');
        }

        return TimeEntry::create([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'seconds' => $seconds,
            'is_billable' => $isBillable,
            'description' => $description,
            'worked_on' => $workedOn,
            'status' => TimeEntryStatus::Draft,
        ]);
    }

    public function submit(TimeEntry $entry): TimeEntry
    {
        if (! in_array($entry->status, [TimeEntryStatus::Draft, TimeEntryStatus::Rejected], true)) {
            throw new \RuntimeException('Only a draft or rejected entry can be submitted.');
        }

        $entry->update(['status' => TimeEntryStatus::Submitted, 'rejected_reason' => null]);

        return $entry->fresh();
    }

    public function approve(TimeEntry $entry, User $approver): TimeEntry
    {
        if ($entry->status !== TimeEntryStatus::Submitted) {
            throw new \RuntimeException('Only a submitted entry can be approved.');
        }

        $rate = $this->rates->currentRateFor($entry->firm, $entry->user);

        $entry->update([
            'status' => TimeEntryStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'billing_rate_cents_snapshot' => $rate?->billing_rate_cents,
        ]);

        return $entry->fresh();
    }

    public function reject(TimeEntry $entry, User $approver, string $reason): TimeEntry
    {
        if ($entry->status !== TimeEntryStatus::Submitted) {
            throw new \RuntimeException('Only a submitted entry can be rejected.');
        }

        $entry->update([
            'status' => TimeEntryStatus::Rejected,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejected_reason' => $reason,
        ]);

        return $entry->fresh();
    }

    /**
     * Called only by InvoiceDraftingService once a line has been
     * created from this entry.
     */
    public function markInvoiced(TimeEntry $entry): TimeEntry
    {
        if (! $entry->isEligibleForInvoicing()) {
            throw new \RuntimeException('Only an approved, billable entry can be marked invoiced.');
        }

        $entry->update(['status' => TimeEntryStatus::Invoiced]);

        return $entry->fresh();
    }
}
