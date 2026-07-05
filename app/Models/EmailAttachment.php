<?php

namespace App\Models;

use App\Enums\DocumentScanStatus;
use App\Enums\EmailAttachmentPromotionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailAttachment — metadata only in this phase; simulated_storage_
 * path mirrors Phase 8's ExportFile pattern (nothing is ever written
 * there). scan_status reuses Phase 4's DocumentScanStatus rather than
 * a new enum (no second scan-status system). document_id is set only
 * by EmailAttachmentPromotionService, and only when promotion_status
 * reaches Promoted.
 */
class EmailAttachment extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'email_message_id',
        'original_filename',
        'mime_type',
        'size_bytes',
        'provider_attachment_id',
        'scan_status',
        'simulated_storage_path',
        'document_id',
        'promotion_status',
        'blocked_reason',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_status' => DocumentScanStatus::class,
            'promotion_status' => EmailAttachmentPromotionStatus::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
