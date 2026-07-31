<?php

namespace App\Enums;

/**
 * FirmOrganizationProvisioningMode — a wizard-input-only choice (never a
 * database column). Firm.organization_id is nullable and optional (see
 * Organization's own docblock: "an optional parent grouping over one or
 * more firms") — this enum exists only to make the Provision Firm
 * wizard's "create new organization" vs "select an existing one" choice
 * an explicit, typed value on FirmProvisioningInput rather than an
 * ambiguous pair of nullable fields.
 */
enum FirmOrganizationProvisioningMode: string
{
    case CreateNew = 'create_new';
    case UseExisting = 'use_existing';
    case None = 'none';
}
