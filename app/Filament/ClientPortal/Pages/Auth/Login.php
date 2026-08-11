<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages\Auth;

use App\Filament\Auth\Concerns\ThrottlesLoginsPerAccount;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Client Portal Login — Mission 1B (Extreme Security Hardening),
 * section 13. Exists to give the Client Portal its own login-throttle
 * identity (see ThrottlesLoginsPerAccount) distinct from the Firm
 * panel, which previously shared Filament's base Login class and
 * therefore its per-IP rate-limit bucket.
 */
class Login extends BaseLogin
{
    use ThrottlesLoginsPerAccount;
}
