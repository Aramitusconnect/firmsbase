<?php

namespace App\Enums;

/**
 * SignatureEventActorType — who caused a signature_events row: firm
 * staff, the external signer (recipient), or an automated system
 * transition (e.g. expiry). Exactly one of actor_firm_user_id/
 * actor_recipient_id is set on the event row when actor_type is
 * FirmUser/Recipient; neither is set when actor_type is System.
 */
enum SignatureEventActorType: string
{
    case FirmUser = 'firm_user';
    case Recipient = 'recipient';
    case System = 'system';
}
