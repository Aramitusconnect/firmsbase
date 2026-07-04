<?php

namespace App\Enums;

/**
 * FirmUserRole — firm_users.role. Exactly 6 values. Deliberately does
 * NOT include `client` — clients are never firm_users; when the client
 * phase lands, clients get their own identity/access model, not a
 * FirmUserRole value. Mixing "internal firm staff" and "external client"
 * into one role enum would blur a permission boundary that must stay
 * hard.
 */
enum FirmUserRole: string
{
    case FirmOwner = 'firm_owner';
    case Attorney = 'attorney';
    case Paralegal = 'paralegal';
    case LegalAssistant = 'legal_assistant';
    case Receptionist = 'receptionist';
    case BillingStaff = 'billing_staff';
}
