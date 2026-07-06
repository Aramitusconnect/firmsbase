<?php

namespace App\Services;

use App\Enums\SubprocessorStatus;
use App\Models\Subprocessor;
use App\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * SubprocessorDisclosureService — customer-facing disclosure entries
 * linked to vendor_register (approved decision #6). No UI is built here
 * — output is what a later phase's admin/portal surface would read.
 */
class SubprocessorDisclosureService
{
    public function disclose(Vendor $vendor, array $attributes): Subprocessor
    {
        return Subprocessor::create(array_merge($attributes, [
            'vendor_register_id' => $vendor->id,
            'status' => $attributes['status'] ?? SubprocessorStatus::Active,
        ]));
    }

    public function retire(Subprocessor $subprocessor): Subprocessor
    {
        $subprocessor->update(['status' => SubprocessorStatus::Removed, 'is_publicly_disclosed' => false]);

        return $subprocessor->fresh();
    }

    /**
     * @return Collection<int, Subprocessor>
     */
    public function listActiveDisclosures(): Collection
    {
        return Subprocessor::query()
            ->where('status', SubprocessorStatus::Active->value)
            ->where('is_publicly_disclosed', true)
            ->get();
    }
}
