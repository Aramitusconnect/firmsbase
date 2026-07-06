<?php

namespace App\Enums;

/**
 * BootCheckStatus — deployment_configs.boot_check_status. Result of
 * the "dedicated/private configuration check at boot" (Master Plan
 * §23 Scope). NotYetRun is the default for a freshly created
 * deployment_configs row before any boot check has executed.
 */
enum BootCheckStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NotYetRun = 'not_yet_run';
}
