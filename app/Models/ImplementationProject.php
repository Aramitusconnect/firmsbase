<?php

namespace App\Models;

use App\Enums\ImplementationProjectStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImplementationProject extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'assigned_to',
        'status',
        'started_at',
        'go_live_at',
        'completed_at',
        'success_review_due_at',
        'success_review_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImplementationProjectStatus::class,
            'started_at' => 'datetime',
            'go_live_at' => 'datetime',
            'completed_at' => 'datetime',
            'success_review_due_at' => 'datetime',
            'success_review_completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_to');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ImplementationTask::class);
    }
}
