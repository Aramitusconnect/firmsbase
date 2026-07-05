<?php

namespace App\Services;

use App\Enums\FormTemplateVersionStatus;
use App\Enums\ImmigrationFormCode;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\PlatformAdmin;

/**
 * FormTemplateService — form_templates/form_template_versions
 * lifecycle. registerFormCode() validates against the exact 7 approved
 * ImmigrationFormCode values. Every version is curated by a
 * PlatformAdmin — no firm ever creates or edits a form_template_version.
 *
 * retire() performs exactly one write: the target version's own
 * status/retired_at/retired_reason. It never touches form_drafts —
 * this is the entire mechanism behind "retiring a form version must
 * not mutate historical drafts."
 */
class FormTemplateService
{
    public function registerFormCode(string $formCode, string $formName): FormTemplate
    {
        ImmigrationFormCode::from($formCode); // throws ValueError if not one of the 7 approved codes

        return FormTemplate::create([
            'form_code' => $formCode,
            'form_name' => $formName,
        ]);
    }

    public function createVersion(FormTemplate $formTemplate, string $editionDate, PlatformAdmin $actor): FormTemplateVersion
    {
        return FormTemplateVersion::create([
            'form_template_id' => $formTemplate->id,
            'edition_date' => $editionDate,
            'status' => FormTemplateVersionStatus::Draft,
            'created_by_platform_admin_id' => $actor->id,
        ]);
    }

    public function activate(FormTemplateVersion $version): FormTemplateVersion
    {
        $version->update(['status' => FormTemplateVersionStatus::Active]);

        return $version->fresh();
    }

    /**
     * The ONLY write this method performs is on $version itself. No
     * form_drafts row is ever read, queried, or updated here.
     */
    public function retire(FormTemplateVersion $version, string $reason): FormTemplateVersion
    {
        $version->update([
            'status' => FormTemplateVersionStatus::Retired,
            'retired_at' => now(),
            'retired_reason' => $reason,
        ]);

        return $version->fresh();
    }
}
