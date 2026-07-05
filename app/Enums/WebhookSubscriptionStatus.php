<?php

namespace App\Enums;

enum WebhookSubscriptionStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
