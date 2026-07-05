<?php

namespace App\Enums;

enum WebhookDeliveryAttemptOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Timeout = 'timeout';
}
