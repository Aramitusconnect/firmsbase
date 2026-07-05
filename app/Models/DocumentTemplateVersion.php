<?php

namespace App\Models;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\DocumentTemplateVersionStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DocumentTemplateVersion — body_template is literal deterministic
 * merge text, never AI-generated. content_status approved only via
 * DocumentTemplateService::approveContent() (typed actor rule — see
 * that service). GeneratedDocumentService/DocumentReviewService
 * re-check this value LIVE at approval time, not a cached snapshot.
 */
class DocumentTemplateVersion extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'document_template_id',
        'version_label',
        'status',
        'merge_fields_schema',
        'body_template',
        'content_status',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentTemplateVersionStatus::class,
            'merge_fields_schema' => 'array',
            'content_status' => DocumentTemplateContentStatus::class,
        ];
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function isActive(): bool
    {
        return $this->status === DocumentTemplateVersionStatus::Active;
    }

    public function isReviewedApproved(): bool
    {
        return $this->content_status === DocumentTemplateContentStatus::ReviewedApproved;
    }
}
