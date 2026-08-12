<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Enums\MarketplaceIntakeEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use Database\Factories\MarketplaceIntakeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MarketplaceIntakeEvent — Mission 3, checkpoint 1. Append-only,
 * mirroring PaymentRequestEvent exactly. actor_firm_user_id is
 * nullable because a public visitor progressing their own intake is
 * never a FirmUser.
 */
class MarketplaceIntakeEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'marketplace_intake_id',
        'event_type',
        'actor_firm_user_id',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => MarketplaceIntakeEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MarketplaceIntakeEventFactory
    {
        return MarketplaceIntakeEventFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('marketplace_intake_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('marketplace_intake_events is append-only and cannot be deleted.');
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
