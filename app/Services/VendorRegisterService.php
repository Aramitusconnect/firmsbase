<?php

namespace App\Services;

use App\Enums\VendorStatus;
use App\Models\PlatformAdmin;
use App\Models\Vendor;

/**
 * VendorRegisterService — internal vendor/processor governance record
 * (approved decision #6). Plain CRUD-style service; no external vendor
 * call is ever made.
 */
class VendorRegisterService
{
    public function register(array $attributes, PlatformAdmin $addedBy): Vendor
    {
        return Vendor::create(array_merge($attributes, [
            'added_by_platform_admin_id' => $addedBy->id,
            'added_at' => now(),
            'status' => $attributes['status'] ?? VendorStatus::Active,
        ]));
    }

    public function updateRiskAssessment(Vendor $vendor, array $attributes): Vendor
    {
        $vendor->update(array_merge($attributes, ['last_reviewed_at' => now()]));

        return $vendor->fresh();
    }

    public function markUnderReview(Vendor $vendor): Vendor
    {
        $vendor->update(['status' => VendorStatus::UnderReview]);

        return $vendor->fresh();
    }

    public function terminate(Vendor $vendor): Vendor
    {
        $vendor->update(['status' => VendorStatus::Terminated]);

        return $vendor->fresh();
    }

    public function markReviewed(Vendor $vendor): Vendor
    {
        $vendor->update(['last_reviewed_at' => now()]);

        return $vendor->fresh();
    }
}
