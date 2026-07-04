<?php

namespace App\Enums;

/**
 * TwoFactorMode — firm_settings.client_2fa_mode. Governs whether client
 * portal logins (a later phase) require 2FA. Attribute only in Phase 1.
 */
enum TwoFactorMode: string
{
    case Optional = 'optional';
    case Required = 'required';
    case Disabled = 'disabled';
}
