<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * ConsultationMode — Mission 2, section 72. Factual consultation modes
 * only, if offered. No scheduling engine in this mission — this is
 * searchable/filterable metadata only.
 */
enum ConsultationMode: string
{
    case InPerson = 'in_person';
    case Phone = 'phone';
    case Video = 'video';
}
