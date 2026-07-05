<?php

namespace App\Enums;

/**
 * SignatureRecipientType — a business-role discriminator for query/UI
 * convenience only. This is separate from, and does not replace, the
 * Phase-6-compatible acknowledger_type (a class-name string) persisted
 * on the ConsentCaptured signature_events row — the two serve
 * different purposes and coexist without creating a second signature
 * system. External covers a signer with no linked Client/Contact/
 * Party/FirmUser record in the system (e.g. a co-applicant not
 * otherwise modeled) — signer_name/signer_email are always captured
 * regardless of recipient_type.
 */
enum SignatureRecipientType: string
{
    case Client = 'client';
    case Contact = 'contact';
    case Party = 'party';
    case FirmUser = 'firm_user';
    case External = 'external';
}
