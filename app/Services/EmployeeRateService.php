<?php

namespace App\Services;

use App\Models\EmployeeRate;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * EmployeeRateService — the only place employee_rates rows are
 * created or closed out. Effective-dated per approved decision: a rate
 * change closes the previous open-ended row (sets its effective_to)
 * and opens a new one, so historical TimeEntry billing_rate_cents_
 * snapshot values (captured at approval time) are never affected by a
 * later rate change.
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

        return DB::transaction(function () use ($firm, $employee, $billingRateCents, $costRateCents, $effectiveFrom, $createdBy, $currency) {
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
     * historical "what rate applied on this date" queries.
     */
    public function currentRateFor(Firm $firm, User $employee, ?\DateTimeInterface $at = null): ?EmployeeRate
    {
        $at ??= now();

        return EmployeeRate::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $employee->id)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->first();
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
