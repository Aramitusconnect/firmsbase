<?php

namespace App\Models;

use App\Enums\MatterLeverageConfidence;
use App\Enums\MatterLeverageRecommendationStatus;
use App\Enums\MatterLeverageRecommendationType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterLeverageRecommendation — Leverage Ratio Optimizer, item 12/13/
 * 23/24. See its own create-table migration for the full dedup/
 * lifecycle rationale. LeverageRecommendationService is the only
 * writer of the fact/evidence portion; LeverageRecommendationLifecycleService
 * is the only writer of the status/acknowledgement/dismissal portion.
 */
class MatterLeverageRecommendation extends Model
{
    use BelongsToTenant, HasFactory;

    private const MUTABLE_FIELDS = [
        'status', 'acknowledged_by_firm_user_id', 'acknowledged_at',
        'dismissed_by_firm_user_id', 'dismissed_at', 'resolution_notes',
    ];

    protected $fillable = [
        'firm_id',
        'matter_id',
        'user_id',
        'recommendation_type',
        'dedup_key',
        'confidence',
        'status',
        'evidence_json',
        'domain_event_id',
        'acknowledged_by_firm_user_id',
        'acknowledged_at',
        'dismissed_by_firm_user_id',
        'dismissed_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'recommendation_type' => MatterLeverageRecommendationType::class,
            'confidence' => MatterLeverageConfidence::class,
            'status' => MatterLeverageRecommendationStatus::class,
            'evidence_json' => 'array',
            'acknowledged_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $recommendation) {
            $dirtyKeys = array_keys($recommendation->getDirty());
            $disallowed = array_diff($dirtyKeys, self::MUTABLE_FIELDS);

            if (! empty($disallowed)) {
                throw new \LogicException(
                    'matter_leverage_recommendations may only update its own lifecycle fields ('.implode(', ', self::MUTABLE_FIELDS).'). '.
                    'Disallowed dirty field(s): '.implode(', ', $disallowed)
                );
            }
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'acknowledged_by_firm_user_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'dismissed_by_firm_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [MatterLeverageRecommendationStatus::Open, MatterLeverageRecommendationStatus::Acknowledged], true);
    }
}
