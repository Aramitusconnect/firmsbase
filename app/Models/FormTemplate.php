<?php

namespace App\Models;

use App\Enums\FormTemplateStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FormTemplate — GLOBAL catalog, no firm_id, no BelongsToTenant
 * (mirrors Phase 2's TemplatePack). A USCIS form's existence is never
 * firm-specific.
 */
class FormTemplate extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'form_code',
        'form_name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => FormTemplateStatus::class,
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormTemplateVersion::class);
    }

    public function watchItems(): HasMany
    {
        return $this->hasMany(FormEditionWatchItem::class);
    }
}
