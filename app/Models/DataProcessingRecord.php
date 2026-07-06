<?php

namespace App\Models;

use App\Enums\DataProcessingRecordStatus;
use App\Enums\DataProcessingRecordType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DataProcessingRecord — informational processing-activity register
 * (approved decision #7). firm_id nullable (null = platform-wide),
 * deliberately does NOT use BelongsToTenant for the same reason as
 * RetentionPolicy/AccessReview. No external call, no compliance claim
 * beyond recorded metadata. Mutable.
 */
class DataProcessingRecord extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'record_type',
        'purpose',
        'data_categories_json',
        'legal_basis',
        'vendor_register_id',
        'subprocessor_id',
        'retention_policy_id',
        'firm_id',
        'status',
        'recorded_by_platform_admin_id',
        'recorded_at',
        'last_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'record_type' => DataProcessingRecordType::class,
            'data_categories_json' => 'array',
            'status' => DataProcessingRecordStatus::class,
            'recorded_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_register_id');
    }

    public function subprocessor(): BelongsTo
    {
        return $this->belongsTo(Subprocessor::class);
    }

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function recordedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'recorded_by_platform_admin_id');
    }
}
