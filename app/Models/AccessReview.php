<?php

namespace App\Models;

use App\Enums\AccessReviewScope;
use App\Enums\AccessReviewStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AccessReview — firm_id nullable (null for platform-scope reviews such
 * as PlatformAdmins/SupportAgents; set for firm-scope reviews such as
 * FirmAdmins). Deliberately does NOT use BelongsToTenant for the same
 * reason as RetentionPolicy — a platform-scope review must remain
 * resolvable regardless of any active tenant context.
 */
class AccessReview extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'scope',
        'status',
        'initiated_by_platform_admin_id',
        'initiated_at',
        'due_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scope' => AccessReviewScope::class,
            'status' => AccessReviewStatus::class,
            'initiated_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function initiatedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'initiated_by_platform_admin_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccessReviewItem::class);
    }
}
