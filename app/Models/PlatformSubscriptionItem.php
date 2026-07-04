<?php

namespace App\Models;

use App\Enums\SeatClass;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSubscriptionItem extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'platform_subscription_id',
        'item_type',
        'seat_class',
        'quantity',
        'unit_amount_cents',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'seat_class' => SeatClass::class,
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscription::class, 'platform_subscription_id');
    }
}
