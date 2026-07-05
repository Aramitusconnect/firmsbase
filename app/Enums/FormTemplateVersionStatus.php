<?php

namespace App\Enums;

/**
 * FormTemplateVersionStatus — form_template_versions.status, one
 * edition's own lifecycle. Retiring a version (project rule) must
 * never mutate any form_drafts row that already references it — see
 * FormTemplateService::retire() and FormDraft's immutable
 * form_template_version_id guard.
 */
enum FormTemplateVersionStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Active = 'active';
    case Retired = 'retired';
}
