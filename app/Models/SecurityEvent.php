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

    /**
     * Section 39A-3L Phase B6 — append-only enforcement guard, mirroring
     * TrustLedgerEntry::booted() exactly. Under the table's FOR
     * INSERT-only RLS write policy, a stray UPDATE/DELETE is a silent
     * 0-affected-rows no-op at the database layer rather than a thrown
     * error, so this app-layer guard is the real enforcement against a
     * mistaken mutation of an existing row.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'security_events is append-only: an existing row can never be updated.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'security_events is append-only: an existing row can never be deleted.'
            );
        });
    }
}
