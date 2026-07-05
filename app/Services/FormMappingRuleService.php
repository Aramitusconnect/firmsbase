<?php

namespace App\Services;

use App\Enums\FormMappingContentStatus;
use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\FormField;
use App\Models\FormMappingRule;
use App\Models\FormTemplateVersion;
use App\Models\PlatformAdmin;

/**
 * FormMappingRuleService — every new rule defaults to SampleOnly.
 * approveContent() is the ONLY path to ReviewedApproved and requires a
 * PlatformAdmin actor — a firm cannot self-certify a USCIS mapping as
 * production-reviewed content (project rule).
 */
class FormMappingRuleService
{
    public function createRule(
        FormTemplateVersion $version,
        FormField $field,
        FormMappingSourceEntity $sourceEntity,
        string $sourcePath,
        FormMappingTransform $transform,
        PlatformAdmin $actor,
    ): FormMappingRule {
        return FormMappingRule::create([
            'form_template_version_id' => $version->id,
            'form_field_id' => $field->id,
            'source_entity' => $sourceEntity,
            'source_path' => $sourcePath,
            'transform' => $transform,
            'content_status' => FormMappingContentStatus::SampleOnly,
            'created_by_platform_admin_id' => $actor->id,
        ]);
    }

    public function approveContent(FormMappingRule $rule, PlatformAdmin $actor): FormMappingRule
    {
        $rule->update([
            'content_status' => FormMappingContentStatus::ReviewedApproved,
            'approved_by_platform_admin_id' => $actor->id,
        ]);

        return $rule->fresh();
    }
}
