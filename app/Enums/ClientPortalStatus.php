<?php

namespace App\Enums;

/**
 * ClientPortalStatus — clients.portal_status. Not given exact values
 * by the master plan (proposed/approved during Phase 2 planning). No
 * actual invitation email/SMS is sent in Phase 2 — that is gated on
 * Phase 4's notification/deliverability infrastructure and on
 * Phase 1's communication-consent enforcement. This enum and the
 * invitation token/expiry columns on `clients` only prepare the schema
 * so Phase 4 has something to hook into.
 */
enum ClientPortalStatus: string
{
    case NotInvited = 'not_invited';
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
}
