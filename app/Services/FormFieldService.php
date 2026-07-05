<?php

namespace App\Services;

use App\Enums\FormFieldType;
use App\Models\FormField;
use App\Models\FormTemplateVersion;

class FormFieldService
{
    public function createField(
        FormTemplateVersion $version,
        string $fieldCode,
        string $fieldLabel,
        FormFieldType $fieldType,
        bool $isRequired = false,
        int $sortOrder = 0,
        ?string $helpText = null,
    ): FormField {
        return FormField::create([
            'form_template_version_id' => $version->id,
            'field_code' => $fieldCode,
            'field_label' => $fieldLabel,
            'field_type' => $fieldType,
            'is_required' => $isRequired,
            'sort_order' => $sortOrder,
            'help_text' => $helpText,
        ]);
    }

    public function listFieldsForVersion(FormTemplateVersion $version): \Illuminate\Support\Collection
    {
        return FormField::query()
            ->where('form_template_version_id', $version->id)
            ->orderBy('sort_order')
            ->get();
    }
}
