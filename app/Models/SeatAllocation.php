<?php

namespace App\Models;

use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SeatAllocation — a firm's seat grant for one seat class, either
 * direct (seat_pool_id null) or drawn from an organization SeatPool.
 * firm_id is NOT NULL — genuinely firm-scoped, so this model uses
 * BelongsToTenant and its table gets Phase 6 RLS.
 */
class SeatAllocation extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'seat_pool_id',
        'seat_class',
        'seats_allocated',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'seat_class' => SeatClass::class,
            'status' => SeatAllocationStatus::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function seatPool(): BelongsTo
    {
        return $this->belongsTo(SeatPool::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPooled(): bool
    {
        return $this->seat_pool_id !== null;
    }
}
