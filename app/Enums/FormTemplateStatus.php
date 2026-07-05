<?php

namespace App\Enums;

/**
 * FormTemplateStatus — form_templates.status. Tracks the form CODE as
 * a whole (e.g. USCIS discontinues I-XXX entirely), distinct from
 * FormTemplateVersionStatus which tracks each edition's own lifecycle.
 */
enum FormTemplateStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
