<?php

namespace App\Models;

use App\Enums\FormTemplateVersionStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FormTemplateVersion — one form edition. No firm_id, no
 * BelongsToTenant (global content). Retiring (status -> retired) never
 * cascades to form_drafts — see FormTemplateService::retire().
 */
class FormTemplateVersion extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'form_template_id',
        'edition_date',
        'status',
        'created_by_platform_admin_id',
        'retired_at',
        'retired_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => FormTemplateVersionStatus::class,
            'retired_at' => 'datetime',
        ];
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class);
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class);
    }

    public function mappingRules(): HasMany
    {
        return $this->hasMany(FormMappingRule::class);
    }

    public function formDrafts(): HasMany
    {
        return $this->hasMany(FormDraft::class);
    }

    public function isActive(): bool
    {
        return $this->status === FormTemplateVersionStatus::Active;
    }
}
