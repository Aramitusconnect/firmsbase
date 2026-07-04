<?php

namespace App\Models;

use App\Enums\SeatClass;
use App\Enums\SeatPoolStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SeatPool — organization-level pooled seats for one seat class.
 * Organization-owned, not firm-owned — no BelongsToTenant, no Phase 6
 * RLS (approved decision).
 */
class SeatPool extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'organization_id',
        'seat_class',
        'total_seats',
        'allocated_seats',
        'counting_mode',
        'period',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'seat_class' => SeatClass::class,
            'status' => SeatPoolStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function seatAllocations(): HasMany
    {
        return $this->hasMany(SeatAllocation::class);
    }

    public function remainingSeats(): int
    {
        return max(0, $this->total_seats - $this->allocated_seats);
    }

    public function isExhausted(): bool
    {
        return $this->allocated_seats >= $this->total_seats;
    }
}
