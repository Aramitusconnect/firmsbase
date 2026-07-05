<?php

namespace App\Enums;

/**
 * EmailProvider — email_accounts.provider. Exactly 2 values per
 * approved Phase 9 scope. Neither value is ever wired to a real SDK or
 * network call in this phase — see FakeEmailProviderClient, the only
 * EmailProviderClient implementation.
 */
enum EmailProvider: string
{
    case Gmail = 'gmail';
    case Microsoft = 'microsoft';
}
