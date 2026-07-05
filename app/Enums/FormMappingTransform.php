<?php

namespace App\Enums;

/**
 * FormMappingTransform — applied by DeterministicFieldResolutionService
 * after resolving a value. A closed, deterministic set — never a
 * user-supplied expression or callback.
 */
enum FormMappingTransform: string
{
    case None = 'none';
    case UppercaseText = 'uppercase_text';
    case TitleCaseText = 'title_case_text';
    case DateFormatUsDate = 'date_format_us_date';
}
