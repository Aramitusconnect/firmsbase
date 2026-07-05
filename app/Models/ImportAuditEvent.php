<?php

namespace App\Models;

use App\Enums\ImportAuditEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ImportAuditEvent — append-only audit trail for an import batch's
 * full lifecycle. No uuid (mirrors SecurityEvent/PlatformBillingEvent).
 * Written exclusively by ImportAuditService.
 */
class ImportAuditEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'import_batch_id',
        'event_type',
        'actor_type',
        'actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ImportAuditEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
