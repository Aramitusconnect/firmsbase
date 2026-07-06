<?php

namespace App\Enums;

/**
 * IntegrationType — integration_degradation_modes.integration_type.
 * Exactly the 4 external dependencies named in the Master Plan Phase
 * 16 scope. Closed set — declaration-only in Phase 16 (approved
 * decision #1): no Stripe/email/virus-scan/telemetry call site is
 * wired to consult this table yet.
 */
enum IntegrationType: string
{
    case Stripe = 'stripe';
    case EmailProvider = 'email_provider';
    case VirusScanning = 'virus_scanning';
    case Telemetry = 'telemetry';
}
