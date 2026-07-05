<?php

namespace App\Models;

use App\Enums\GeneratedDocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GeneratedDocument — firm-owned workflow root. used_sample_content
 * (final correction) is refreshed LIVE by DocumentReviewService::
 * approve() against the CURRENT document_template_version.content_status
 * — never trusted as a permanent generation-time snapshot. simulated_
 * storage_path is metadata only; nothing is ever written there.
 */
class GeneratedDocument extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'client_id',
        'document_template_version_id',
        'status',
        'simulated_storage_path',
        'used_sample_content',
        'generated_by_firm_user_id',
        'reviewed_by_firm_user_id',
        'reviewed_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GeneratedDocumentStatus::class,
            'used_sample_content' => 'boolean',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function documentTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class);
    }

    public function generatedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'generated_by_firm_user_id');
    }

    public function reviewedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'reviewed_by_firm_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GeneratedDocumentEvent::class);
    }
}
