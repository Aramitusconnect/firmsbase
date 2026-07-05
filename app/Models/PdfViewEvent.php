<?php

namespace App\Models;

use App\Enums\PdfAnnotationType;
use App\Enums\PdfViewEventAction;
use App\Enums\PdfViewerViewerType;
use App\Enums\SignatureSourceDocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PdfViewEvent — the append-only PDF view/download/annotation ledger.
 * No pdf_view_sessions or pdf_annotation_events table exists; view,
 * download-decision, and (if enabled) annotation events are all
 * represented as rows of this one table (see PdfViewEventAction).
 * Fully immutable from creation via the booted() guard below.
 */
class PdfViewEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'viewer_type',
        'viewer_firm_user_id',
        'viewer_recipient_id',
        'source_document_type',
        'document_id',
        'generated_document_id',
        'action',
        'annotation_type',
        'annotation_page_number',
        'annotation_content',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'viewer_type' => PdfViewerViewerType::class,
            'source_document_type' => SignatureSourceDocumentType::class,
            'action' => PdfViewEventAction::class,
            'annotation_type' => PdfAnnotationType::class,
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event) {
            if ($event->exists) {
                throw new \LogicException(
                    'pdf_view_events rows are append-only and immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $event) {
            throw new \LogicException(
                'pdf_view_events rows are append-only and immutable — an existing row can never be deleted.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function viewerFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'viewer_firm_user_id');
    }

    public function viewerRecipient(): BelongsTo
    {
        return $this->belongsTo(SignatureRequestRecipient::class, 'viewer_recipient_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }
}
