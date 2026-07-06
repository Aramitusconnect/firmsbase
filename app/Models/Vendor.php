<?php

namespace App\Models;

use App\Enums\DataCategory;
use App\Enums\VendorAiZeroRetentionStatus;
use App\Enums\VendorDpaStatus;
use App\Enums\VendorRiskLevel;
use App\Enums\VendorSocReportStatus;
use App\Enums\VendorStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vendor — table vendor_register (approved decision #6: internal
 * vendor/processor governance record). Named Vendor, not VendorRegister,
 * since each row represents one vendor; $table is explicitly overridden
 * because Eloquent's default pluralization would otherwise look for
 * "vendors". Mutable — status/review fields are expected to change over
 * a vendor's lifecycle (project guidance: "vendor/data-processing
 * records may be mutable if status/update workflows require it").
 */
class Vendor extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'vendor_register';

    protected $fillable = [
        'vendor_name',
        'service_purpose',
        'data_category',
        'risk_level',
        'dpa_status',
        'soc_report_status',
        'ai_zero_retention_status',
        'incident_contact_name',
        'incident_contact_email',
        'incident_contact_phone',
        'status',
        'added_by_platform_admin_id',
        'added_at',
        'last_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'data_category' => DataCategory::class,
            'risk_level' => VendorRiskLevel::class,
            'dpa_status' => VendorDpaStatus::class,
            'soc_report_status' => VendorSocReportStatus::class,
            'ai_zero_retention_status' => VendorAiZeroRetentionStatus::class,
            'status' => VendorStatus::class,
            'added_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function addedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'added_by_platform_admin_id');
    }

    public function subprocessors(): HasMany
    {
        return $this->hasMany(Subprocessor::class, 'vendor_register_id');
    }
}
