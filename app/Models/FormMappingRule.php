<?php

namespace App\Models;

use App\Enums\FormMappingContentStatus;
use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormMappingRule — content_status is the exact mechanism behind "do
 * not hardcode production USCIS field maps unless explicitly marked
 * reviewed/approved." Only FormMappingRuleService::approveContent()
 * (PlatformAdmin actor required) may move this to ReviewedApproved.
 */
class FormMappingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_template_version_id',
        'form_field_id',
        'source_entity',
        'source_path',
        'transform',
        'content_status',
        'created_by_platform_admin_id',
        'approved_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'source_entity' => FormMappingSourceEntity::class,
            'transform' => FormMappingTransform::class,
            'content_status' => FormMappingContentStatus::class,
        ];
    }

    public function formTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(FormTemplateVersion::class);
    }

    public function formField(): BelongsTo
    {
        return $this->belongsTo(FormField::class);
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function approvedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'approved_by_platform_admin_id');
    }

    public function isReviewedApproved(): bool
    {
        return $this->content_status === FormMappingContentStatus::ReviewedApproved;
    }
}
