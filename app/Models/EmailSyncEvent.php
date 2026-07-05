<?php

namespace App\Models;

use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailSyncEvent — pure append-only audit/event row. Has firm_id for
 * direct firm-scoped queries but deliberately does NOT use
 * BelongsToTenant (Phase 8 ImportAuditEvent precedent — audit tables
 * are queried explicitly by services, not globally scoped). No uuid.
 * created_at only, no updated_at.
 */
class EmailSyncEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'email_account_id',
        'event_type',
        'outcome',
        'resulting_cursor',
        'detail',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => EmailSyncEventType::class,
            'outcome' => EmailSyncOutcome::class,
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }
}
