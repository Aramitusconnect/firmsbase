<?php

declare(strict_types=1);

namespace App\Filament\Auth\Pages;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;

/**
 * Platform Admin password-reset request page — Mission 1B (Extreme
 * Security Hardening), section 13. Exists purely to give the highest-
 * security panel its own IP-based rate-limit bucket (Filament's
 * WithRateLimiting keys by component class + IP) — previously this
 * step shared Filament's base RequestPasswordReset class across all
 * three panels.
 */
class PlatformAdminRequestPasswordReset extends BaseRequestPasswordReset {}
