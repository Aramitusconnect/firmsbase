<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages\Auth;

use App\Filament\Auth\Concerns\ThrottlesLoginsPerAccount;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Firm-panel Login — Mission 1B (Extreme Security Hardening), section
 * 13. Exists to give the Firm panel its own login-throttle identity
 * (see ThrottlesLoginsPerAccount) distinct from the Client Portal,
 * which previously shared Filament's base Login class and therefore
 * its per-IP rate-limit bucket.
 */
class Login extends BaseLogin
{
    use ThrottlesLoginsPerAccount;
}
