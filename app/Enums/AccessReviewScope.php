<?php

namespace App\Enums;

enum AccessReviewScope: string
{
    case PlatformAdmins = 'platform_admins';
    case SupportAgents = 'support_agents';
    case FirmAdmins = 'firm_admins';
    case ApiKeys = 'api_keys';
    case Webhooks = 'webhooks';
    case AiTools = 'ai_tools';
    case EmployeeRoles = 'employee_roles';
}
