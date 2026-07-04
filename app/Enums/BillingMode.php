<?php

namespace App\Enums;

/**
 * BillingMode — firm_licenses.billing_mode. Exact 6 values approved for
 * Phase 6:
 *   self_service  — normal customer-managed card/subscription billing.
 *   manual        — manually managed by platform/admin.
 *   invoice       — invoice-based platform billing.
 *   consolidated  — organization-level consolidated billing account.
 *   comped        — free/internal/special grant.
 *   lifetime      — lifetime license billing mode.
 * This is distinct from firm_settings.payment_mode (Phase 1), which
 * governs firm-CLIENT payment processing (operating/trust/blocked) —
 * BillingMode governs how the FIRM ITSELF is billed by the platform.
 * The two must never be confused or merged.
 */
enum BillingMode: string
{
    case SelfService = 'self_service';
    case Manual = 'manual';
    case Invoice = 'invoice';
    case Consolidated = 'consolidated';
    case Comped = 'comped';
    case Lifetime = 'lifetime';
}
