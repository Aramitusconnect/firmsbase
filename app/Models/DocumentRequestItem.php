<?php

namespace App\Models;

use App\Enums\DocumentRequestItemStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DocumentRequestItem — status values are verbatim from the master
 * plan PDF, Section 33. No own firm_id — scoped transitively through
 * document_request_id.
 */
class DocumentRequestItem extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'document_request_id',
        'label',
        'status',
        'is_required',
        'viewed_at',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'rejected_reason',
        'waived_by',
        'waived_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentRequestItemStatus::class,
            'is_required' => 'boolean',
            'viewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'waived_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function chaseEvents(): HasMany
    {
        return $this->hasMany(DocumentChaseEvent::class);
    }

    /**
     * "Client reminders stop when approved, waived, expired, or
     * paused by staff" (PDF, Document request item row).
     */
    public function isChaseEligibleStatus(): bool
    {
        return in_array($this->status, [
            DocumentRequestItemStatus::Requested,
            DocumentRequestItemStatus::Viewed,
            DocumentRequestItemStatus::NeedsReplacement,
        ], true);
    }
}
