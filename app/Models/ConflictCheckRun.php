<?php

namespace App\Models;

use App\Enums\ConflictCheckRunStatus;
use App\Enums\ConflictCheckScope;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConflictCheckRun extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'requested_by',
        'status',
        'scope',
        'searched_terms_json',
        'result_count',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConflictCheckRunStatus::class,
            'scope' => ConflictCheckScope::class,
            'searched_terms_json' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ConflictCheckResult::class);
    }
}
