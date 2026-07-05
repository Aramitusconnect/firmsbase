<?php

namespace App\Models;

use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SignatureRequest — firm-owned workflow root. Exactly one of
 * document()/generatedDocument() is set, per source_document_type
 * (service-enforced XOR — see SignatureRequestWorkflowService).
 * status is derived/advanced by SignatureRequestAggregationService,
 * never written directly except for the direct staff actions (send,
 * void) — see that service's docblock for the full aggregation rules.
 */
class SignatureRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'client_id',
        'source_document_type',
        'document_id',
        'generated_document_id',
        'status',
        'title',
        'requested_by_firm_user_id',
        'attorney_reviewed_at',
        'attorney_reviewed_by_firm_user_id',
        'attorney_review_notes',
        'expires_at',
        'sent_at',
        'completed_at',
        'voided_at',
        'declined_at',
        'declined_reason',
    ];

    protected function casts(): array
    {
        return [
            'source_document_type' => SignatureSourceDocumentType::class,
            'status' => SignatureRequestStatus::class,
            'attorney_reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
            'declined_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }

    public function requestedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'requested_by_firm_user_id');
    }

    public function attorneyReviewedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'attorney_reviewed_by_firm_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SignatureRequestRecipient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(SignatureCertificate::class);
    }

    public function isAttorneyReviewed(): bool
    {
        return $this->attorney_reviewed_at !== null;
    }
}
