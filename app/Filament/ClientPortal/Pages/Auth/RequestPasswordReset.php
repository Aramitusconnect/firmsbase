<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages\Auth;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;

/**
 * Client Portal password-reset request page — Mission 1B (Extreme
 * Security Hardening), section 13. Exists purely to give the Client
 * Portal its own IP-based rate-limit bucket (Filament's
 * WithRateLimiting keys by component class + IP) — previously this
 * step shared Filament's base RequestPasswordReset class across all
 * three panels.
 */
class RequestPasswordReset extends BaseRequestPasswordReset {}
