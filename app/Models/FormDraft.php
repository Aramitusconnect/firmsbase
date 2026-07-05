<?php

namespace App\Models;

use App\Enums\FormDraftStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FormDraft — firm-owned workflow root. form_template_version_id is
 * immutable after creation (project rule: "historical drafts must
 * retain form_template_version_id"), enforced the same way
 * HasPublicUuid enforces uuid immutability: throws if the column is
 * dirty on an existing, persisted record. status uses the exact 8
 * approved FormDraftStatus values; used_sample_mapping is a cached
 * flag refreshed live by FormReviewService::approve(), never trusted
 * as a permanent snapshot from generation time.
 */
class FormDraft extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'client_id',
        'form_template_version_id',
        'status',
        'used_sample_mapping',
        'generated_by_firm_user_id',
        'reviewed_by_firm_user_id',
        'reviewed_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FormDraftStatus::class,
            'used_sample_mapping' => 'boolean',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $draft) {
            if ($draft->exists && $draft->isDirty('form_template_version_id')) {
                throw new \LogicException(
                    'form_template_version_id is immutable after creation — historical drafts must retain their original version reference.'
                );
            }
        });
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

    public function formTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(FormTemplateVersion::class);
    }

    public function generatedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'generated_by_firm_user_id');
    }

    public function reviewedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'reviewed_by_firm_user_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(FormDraftValue::class);
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(FormReviewEvent::class);
    }

    public function missingDataItems(): HasMany
    {
        return $this->hasMany(FormMissingDataItem::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(FormReviewChecklistItem::class);
    }
}
