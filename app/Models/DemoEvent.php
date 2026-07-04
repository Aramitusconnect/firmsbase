<?php

namespace App\Models;

use App\Enums\DemoEventStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoEvent extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'opportunity_id',
        'scheduled_at',
        'held_at',
        'status',
        'conducted_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemoEventStatus::class,
            'scheduled_at' => 'datetime',
            'held_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function conductedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'conducted_by');
    }
}
