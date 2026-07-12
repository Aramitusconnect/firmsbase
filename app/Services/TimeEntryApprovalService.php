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
 *
 * Section 39A-3L, Checkpoint 21 — time_entries now has FORCE ROW LEVEL
 * SECURITY active, so every read/write must run with
 * app.current_firm_id set to the row's own firm. createManualEntry(),
 * submit(), reject(), and markInvoiced() each wrap their entire body in
 * a single runWithFirmContext() call. approve() is the one exception:
 * see its own docblock below for why it deliberately uses two separate,
 * tightly-scoped context activations instead of one whole-method wrap.
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

        return (new TenantContextService())->runWithFirmContext($firm, fn () => TimeEntry::create([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'seconds' => $seconds,
            'is_billable' => $isBillable,
            'description' => $description,
            'worked_on' => $workedOn,
            'status' => TimeEntryStatus::Draft,
        ]));
    }

    public function submit(TimeEntry $entry): TimeEntry
    {
        if (! in_array($entry->status, [TimeEntryStatus::Draft, TimeEntryStatus::Rejected], true)) {
            throw new \RuntimeException('Only a draft or rejected entry can be submitted.');
        }

        return (new TenantContextService())->runWithFirmContext($entry->firm_id, function () use ($entry) {
            $entry->update(['status' => TimeEntryStatus::Submitted, 'rejected_reason' => null]);

            return $entry->fresh();
        });
    }

    public function approve(TimeEntry $entry, User $approver): TimeEntry
    {
        if ($entry->status !== TimeEntryStatus::Submitted) {
            throw new \RuntimeException('Only a submitted entry can be approved.');
        }

        // Called UNWRAPPED, deliberately — currentRateFor() self-wraps its
        // own body (see its docblock). Wrapping this call inside another
        // outer context here would let its inner self-wrap clear that
        // outer context before the update() below runs (the decoy-wrap bug
        // this arc has repeatedly had to avoid). Keep these two operations
        // as two independent, tightly-scoped context activations.
        $rate = $this->rates->currentRateFor($entry->firm, $entry->user);

        return (new TenantContextService())->runWithFirmContext($entry->firm_id, function () use ($entry, $approver, $rate) {
            $entry->update([
                'status' => TimeEntryStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'billing_rate_cents_snapshot' => $rate?->billing_rate_cents,
            ]);

            return $entry->fresh();
        });
    }

    public function reject(TimeEntry $entry, User $approver, string $reason): TimeEntry
    {
        if ($entry->status !== TimeEntryStatus::Submitted) {
            throw new \RuntimeException('Only a submitted entry can be rejected.');
        }

        return (new TenantContextService())->runWithFirmContext($entry->firm_id, function () use ($entry, $approver, $reason) {
            $entry->update([
                'status' => TimeEntryStatus::Rejected,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejected_reason' => $reason,
            ]);

            return $entry->fresh();
        });
    }

    /**
     * Called by InvoiceDraftingService::draftFromTimeEntries() from
     * inside its own outer DB::transaction() — this self-wrap's internal
     * transaction correctly becomes a savepoint in that case (see
     * TenantContextService), so no change is needed in the caller.
     */
    public function markInvoiced(TimeEntry $entry): TimeEntry
    {
        if (! $entry->isEligibleForInvoicing()) {
            throw new \RuntimeException('Only an approved, billable entry can be marked invoiced.');
        }

        return (new TenantContextService())->runWithFirmContext($entry->firm_id, function () use ($entry) {
            $entry->update(['status' => TimeEntryStatus::Invoiced]);

            return $entry->fresh();
        });
    }
}
