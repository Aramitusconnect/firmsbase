<?php

namespace App\Enums;

enum FormFieldType: string
{
    case Text = 'text';
    case Date = 'date';
    case Checkbox = 'checkbox';
    case Number = 'number';
    case Select = 'select';
}
