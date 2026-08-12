<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\Firm;
use Database\Factories\MarketplaceAiUsageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketplaceAiUsageEvent — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 6. Append-only, mirroring PaymentRequestEvent/
 * MarketplaceIntakeEvent's own append-only shape. See its own
 * migration for the full "why this is genuinely separate from
 * ai_usage_events" rationale.
 */
class MarketplaceAiUsageEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'marketplace_intake_id',
        'session_hash',
        'ip_address',
        'provider',
        'model',
        'action_type',
        'tokens_in',
        'tokens_out',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'action_type' => AiUsageActionType::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MarketplaceAiUsageEventFactory
    {
        return MarketplaceAiUsageEventFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('marketplace_ai_usage_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('marketplace_ai_usage_events is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function marketplaceIntake(): BelongsTo
    {
        return $this->belongsTo(MarketplaceIntake::class);
    }
}
