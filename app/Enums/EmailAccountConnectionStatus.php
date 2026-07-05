<?php

namespace App\Enums;

/**
 * EmailAccountConnectionStatus — email_accounts.connection_status.
 * PendingAuthorization exists for symmetry with a future real OAuth
 * flow but is never reached by anything in this phase — connect() in
 * EmailAccountService moves straight to Connected once a (fixture)
 * authorization result is supplied, since no real callback exists yet.
 */
enum EmailAccountConnectionStatus: string
{
    case PendingAuthorization = 'pending_authorization';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Revoked = 'revoked';
    case Error = 'error';
}
