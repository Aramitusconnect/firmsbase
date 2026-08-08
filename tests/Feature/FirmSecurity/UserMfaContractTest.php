<?php

declare(strict_types=1);

namespace Tests\Feature\FirmSecurity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserMfaContractTest — Firm Feature Manifest §11. Proves `App\Models\
 * User`'s `HasAppAuthentication`/`HasAppAuthenticationRecovery` contract
 * mapping onto `two_factor_secret`/`two_factor_recovery_codes`/
 * `two_factor_confirmed_at` is exactly what `Filament\Auth\MultiFactor\
 * App\AppAuthentication` (and every Filament vendor Action built on top
 * of it) relies on — identical shape and behavior to
 * `PlatformAdminModelMfaContractTest`'s own proof for `PlatformAdmin`.
 * This contract is a hard precondition for ANY firm user to be able to
 * log in at all once FirmPanelProvider registers a multi-factor
 * provider (see that provider's own docblock) — not merely for the
 * enrollment feature itself.
 */
final class UserMfaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_secret_stamps_two_factor_confirmed_at(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => null]);

        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->refresh();

        $this->assertSame('JBSWY3DPEHPK3PXP', $user->getAppAuthenticationSecret());
        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_clearing_the_secret_nulls_two_factor_confirmed_at(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $user->saveAppAuthenticationSecret(null);
        $user->refresh();

        $this->assertNull($user->getAppAuthenticationSecret());
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_holder_name_is_the_user_email(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.test']);

        $this->assertSame('someone@example.test', $user->getAppAuthenticationHolderName());
    }

    public function test_recovery_codes_round_trip(): void
    {
        $user = User::factory()->create();

        $user->saveAppAuthenticationRecoveryCodes(['hash-one', 'hash-two']);
        $user->refresh();

        $this->assertSame(['hash-one', 'hash-two'], $user->getAppAuthenticationRecoveryCodes());

        $user->saveAppAuthenticationRecoveryCodes(null);
        $user->refresh();

        $this->assertNull($user->getAppAuthenticationRecoveryCodes());
    }
}
