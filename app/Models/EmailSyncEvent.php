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
 *
 * Append-only (required companion fix landing alongside this table's
 * FORCE ROW LEVEL SECURITY activation — see database/migrations/
 * 2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php):
 * booted() throws on any update/delete of an existing row, mirroring
 * AiApprovalEvent's exact immutability pattern. RLS's WITH CHECK
 * clause governs INSERT-time firm ownership only and is NOT the
 * append-only mechanism — that guarantee comes exclusively from this
 * guard. The only writer is EmailSyncAuditService::record().
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

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('email_sync_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('email_sync_events is append-only and cannot be deleted.');
        });
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
