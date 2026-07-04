<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SecurityEvent — append-only audit log. No updated_at column exists;
 * UPDATED_AT is disabled so Eloquent never attempts to write one. No
 * uuid — high-volume internal log, not addressed individually via a
 * public API/route.
 */
class SecurityEvent extends Model
{
    use HasFactory, BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'actor_type',
        'actor_id',
        'event_type',
        'category',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
