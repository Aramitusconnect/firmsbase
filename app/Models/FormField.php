<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_template_version_id',
        'field_code',
        'field_label',
        'field_type',
        'is_required',
        'sort_order',
        'help_text',
    ];

    protected function casts(): array
    {
        return [
            'field_type' => FormFieldType::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function formTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(FormTemplateVersion::class);
    }

    public function mappingRules(): HasMany
    {
        return $this->hasMany(FormMappingRule::class);
    }
}
