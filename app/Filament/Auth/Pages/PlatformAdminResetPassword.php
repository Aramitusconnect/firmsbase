<?php

declare(strict_types=1);

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;

/**
 * Platform Admin password-reset (token-consumption) page — Mission 1B
 * (Extreme Security Hardening), section 13. Exists purely to give the
 * highest-security panel its own IP-based rate-limit bucket
 * (Filament's WithRateLimiting keys by component class + IP) —
 * previously this step shared Filament's base ResetPassword class
 * with the Client Portal panel.
 */
class PlatformAdminResetPassword extends BaseResetPassword {}
