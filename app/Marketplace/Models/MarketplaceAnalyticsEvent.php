<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\MarketplaceAnalyticsEventType;
use Database\Factories\MarketplaceAnalyticsEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * MarketplaceAnalyticsEvent — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 13. Append-only, no uuid (mirrors
 * ProductAnalyticsEvent/SecurityEvent's own "high-volume internal
 * event stream, never addressed individually by a public identifier"
 * shape). See the migration's own docblock for the full privacy
 * rationale (no IP, no session/cookie id, no actor of any kind).
 *
 * A "dumb" model — the only write path is MarketplaceAnalyticsService,
 * matching every other Mission 2 event/workflow model's established
 * convention.
 */
class MarketplaceAnalyticsEvent extends Model
{
    use HasFactory;

    protected $table = 'directory_marketplace_analytics_events';

    const UPDATED_AT = null;

    protected $fillable = [
        'event_type',
        'subject_type',
        'subject_id',
        'dimensions',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => MarketplaceAnalyticsEventType::class,
            'dimensions' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MarketplaceAnalyticsEventFactory
    {
        return MarketplaceAnalyticsEventFactory::new();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
