<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;

/**
 * Client Portal password-reset (token-consumption) page — Mission 1B
 * (Extreme Security Hardening), section 13. Exists purely to give the
 * Client Portal its own IP-based rate-limit bucket (Filament's
 * WithRateLimiting keys by component class + IP) — previously this
 * step shared Filament's base ResetPassword class with the Platform
 * Admin panel. (The Firm panel already has its own subclass,
 * App\Filament\Firm\Pages\Auth\ResetPassword, for an unrelated
 * owner-invitation reason — this class has no equivalent behavior
 * change, it exists solely for bucket isolation.)
 */
class ResetPassword extends BaseResetPassword {}
