<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SesBounceType — the SES event JSON's `bounce.bounceType` field. Only
 * Permanent bounces suppress; Transient and Undetermined never do
 * (project rule — see SesEventConsumerService).
 */
enum SesBounceType: string
{
    case Permanent = 'Permanent';
    case Transient = 'Transient';
    case Undetermined = 'Undetermined';
}
