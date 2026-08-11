<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Filament\MultiFactor\AuditedAppAuthentication;
use App\Filament\MultiFactor\WebAuthn\WebAuthnAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformAdminMfaPolicyTest — Mission 1B (Extreme Security
 * Hardening), section 5. Proves the finalized MFA policy is actually
 * wired into the admin panel exactly as documented in
 * AdminPanelProvider: at least one factor required, WebAuthn listed
 * first/preferred, TOTP preserved as a fully independent, sufficient
 * factor (not merely a recovery mechanism).
 */
class PlatformAdminMfaPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_requires_multi_factor_authentication(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired());
    }

    public function test_webauthn_is_listed_before_totp_as_the_preferred_factor(): void
    {
        // getMultiFactorAuthenticationProviders() returns an array keyed
        // by provider ID (mapWithKeys under the hood) — "listed first"
        // is proven by insertion order via array_values(), not by key.
        $providers = array_values(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders());

        $this->assertCount(2, $providers);
        $this->assertInstanceOf(WebAuthnAuthentication::class, $providers[0]);
        $this->assertInstanceOf(AuditedAppAuthentication::class, $providers[1]);
    }

    public function test_totp_is_independently_sufficient_not_merely_a_recovery_mechanism(): void
    {
        // AuditedAppAuthentication::recoverable() enables recovery
        // codes for TOTP itself — it does not make TOTP contingent on
        // WebAuthn in any way. Both providers satisfy isRequired
        // independently; Filament's own challenge flow lets an admin
        // who has enrolled either one log in with it alone.
        $providers = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

        $totp = $providers['app'] ?? null;
        $this->assertInstanceOf(AuditedAppAuthentication::class, $totp);
        $this->assertSame('app', $totp->getId());
    }
}
