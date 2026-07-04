<?php

namespace App\Models;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Announcement — targeting columns live directly on the row
 * (organization_id/firm_id/plan_id/module_code, all nullable = null
 * means broadcast/global). Carries both `severity` (its own level) and
 * `min_severity` (an optional targeting/filter threshold), per the
 * approved manifest correction. No announcement_targets table.
 */
class Announcement extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'organization_id',
        'firm_id',
        'plan_id',
        'module_code',
        'min_severity',
        'type',
        'severity',
        'status',
        'title',
        'body',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'min_severity' => AnnouncementSeverity::class,
            'type' => AnnouncementType::class,
            'severity' => AnnouncementSeverity::class,
            'status' => AnnouncementStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by');
    }

    public function isBroadcast(): bool
    {
        return $this->organization_id === null
            && $this->firm_id === null
            && $this->plan_id === null
            && $this->module_code === null;
    }
}
