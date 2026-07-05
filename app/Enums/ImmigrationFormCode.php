<?php

namespace App\Enums;

/**
 * ImmigrationFormCode — the exact 7 approved Phase 10 starter forms.
 * FormTemplateService::registerFormCode() validates against this enum;
 * form_templates.form_code itself remains a plain string column so a
 * later phase can add more codes without a schema migration.
 */
enum ImmigrationFormCode: string
{
    case I130 = 'I-130';
    case I485 = 'I-485';
    case I765 = 'I-765';
    case I864 = 'I-864';
    case I589 = 'I-589';
    case N400 = 'N-400';
    case AR11 = 'AR-11';
}
