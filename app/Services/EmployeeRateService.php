<?php

namespace App\Services;

use App\Models\EmployeeRate;
use App\Models\Firm;
use App\Models\User;

/**
 * EmployeeRateService — the only place employee_rates rows are
 * created or closed out. Effective-dated per approved decision: a rate
 * change closes the previous open-ended row (sets its effective_to)
 * and opens a new one, so historical TimeEntry billing_rate_cents_
 * snapshot values (captured at approval time) are never affected by a
 * later rate change.
 *
 * Section 39A-3K — employee_rates now has FORCE ROW LEVEL SECURITY
 * active, so every read/write must run with app.current_firm_id set to
 * the row's own firm. setRate()/currentRateFor() each wrap their own
 * body in runWithFirmContext($firm, ...) directly, the same
 * self-wrapping convention already established by DeadlineService
 * (this service, like DeadlineService, is documented as "the only
 * place employee_rates rows are created or closed out" and has no
 * production caller today that already establishes context — see the
 * batch report). openRateFor() stays unwrapped: it is a private helper
 * only ever invoked from inside setRate()'s own already-active
 * context, so wrapping it too would nest and clear that context
 * prematurely.
 *
 * Known residual gap (documented, not fixed in this batch): this
 * service does not verify the given $employee actually holds a
 * firm_users membership row for $firm before writing a rate. No such
 * check exists anywhere in the codebase today (no FK, no form-request
 * validation, no authorization policy), and the existing
 * EmployeeRateServiceTest suite explicitly exercises setRate() for a
 * User with no firm_users tie at all — so adding membership
 * enforcement here would be a business-authorization design change,
 * not narrow tenant-context wiring, and was left out of this batch.
 * This gap is orthogonal to FORCE ROW LEVEL SECURITY: the policy below
 * still correctly isolates every row by its own firm_id regardless of
 * whether user_id is a legitimate member of that firm.
 */
class EmployeeRateService
{
    public function setRate(
        Firm $firm,
        User $employee,
        int $billingRateCents,
        int $costRateCents,
        ?\DateTimeInterface $effectiveFrom = null,
        ?User $createdBy = null,
        string $currency = 'usd',
    ): EmployeeRate {
        $effectiveFrom ??= now();

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $employee, $billingRateCents, $costRateCents, $effectiveFrom, $createdBy, $currency) {
            // runWithFirmContext() already wraps this callback in its
            // own DB::transaction() (see TenantContextService), which
            // is what actually provides setRate()'s original
            // close-then-open atomicity — no separate DB::transaction()
            // is needed here.
            $current = $this->openRateFor($firm, $employee);

            if ($current) {
                $current->update(['effective_to' => $effectiveFrom]);
            }

            return EmployeeRate::create([
                'firm_id' => $firm->id,
                'user_id' => $employee->id,
                'billing_rate_cents' => $billingRateCents,
                'cost_rate_cents' => $costRateCents,
                'currency' => $currency,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'created_by' => $createdBy?->id,
            ]);
        });
    }

    /**
     * The rate that was/is active for the employee as of $at (defaults
     * to now). Used both for live billing-rate lookups and for
     * historical "what rate applied on this date" queries. Self-wraps
     * in the firm's own context (see class docblock) since its one
     * current caller (TimeEntryApprovalService::approve(), itself not
     * reachable from any production entry point today — see the batch
     * report) does not establish context either.
     */
    public function currentRateFor(Firm $firm, User $employee, ?\DateTimeInterface $at = null): ?EmployeeRate
    {
        $at ??= now();

        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $employee, $at) {
            return EmployeeRate::query()
                ->where('firm_id', $firm->id)
                ->where('user_id', $employee->id)
                ->where('effective_from', '<=', $at)
                ->where(function ($q) use ($at) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>', $at);
                })
                ->orderByDesc('effective_from')
                ->first();
        });
    }

    private function openRateFor(Firm $firm, User $employee): ?EmployeeRate
    {
        return EmployeeRate::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $employee->id)
            ->whereNull('effective_to')
            ->first();
    }
}
