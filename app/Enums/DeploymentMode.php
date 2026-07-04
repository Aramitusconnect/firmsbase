<?php

namespace App\Enums;

/**
 * DeploymentMode — firms.deployment_mode. Carried as plain metadata via
 * the tenancy abstraction layer (project rule 11) — application code
 * must not branch business logic on this value. Dedicated/private
 * deployment customization happens through configuration, entitlements,
 * template packs, APIs, and webhooks (project rule 18), never a
 * codebase fork, and never ad hoc `if (deploymentMode === ...)` checks
 * scattered through feature code.
 */
enum DeploymentMode: string
{
    case Saas = 'saas';
    case Dedicated = 'dedicated';
    case PrivateEnterprise = 'private_enterprise';
}
