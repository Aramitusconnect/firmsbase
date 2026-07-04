<?php

namespace App\Models;

use App\Enums\SalesAssignmentStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SalesRepAssignment extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'assignable_type',
        'assignable_id',
        'platform_admin_id',
        'status',
        'assigned_at',
        'reassigned_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesAssignmentStatus::class,
            'assigned_at' => 'datetime',
            'reassigned_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }
}
