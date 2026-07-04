<?php

namespace App\Enums;

enum AnnouncementType: string
{
    case General = 'general';
    case Maintenance = 'maintenance';
    case ModuleUpdate = 'module_update';
    case BillingPolicy = 'billing_policy';
    case Security = 'security';
}
