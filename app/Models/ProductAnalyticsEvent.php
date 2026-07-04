<?php

namespace App\Models;

use App\Enums\ProductAnalyticsEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductAnalyticsEvent — append-only, no uuid (high-volume internal
 * event stream, mirrors SecurityEvent/PlatformBillingEvent — never
 * addressed individually by a public identifier). Not tenant-owned, no
 * BelongsToTenant — read in aggregate by platform staff across firms.
 */
class ProductAnalyticsEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'actor_type',
        'actor_id',
        'event_type',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ProductAnalyticsEventType::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
