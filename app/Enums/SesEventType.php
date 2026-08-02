<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SesEventType — the exact five event types this SES configuration
 * set (`my-first-configuration-set`) is configured to publish, per the
 * SES event JSON's own top-level `eventType` field.
 */
enum SesEventType: string
{
    case Bounce = 'Bounce';
    case Complaint = 'Complaint';
    case Reject = 'Reject';
    case RenderingFailure = 'Rendering Failure';
    case DeliveryDelay = 'DeliveryDelay';
}
