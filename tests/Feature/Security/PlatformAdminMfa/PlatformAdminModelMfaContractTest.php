<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformAdminModelMfaContractTest — MFA design proposal §2. Proves
 * PlatformAdmin's HasAppAuthentication/HasAppAuthenticationRecovery
 * contract mapping onto two_factor_secret/two_factor_recovery_codes/
 * two_factor_confirmed_at is exactly what AuditedAppAuthentication (and
 * every Filament vendor Action built on top of it) will rely on: saving
 * a non-null secret stamps two_factor_confirmed_at, saving a null
 * secret clears it.
 */
class PlatformAdminModelMfaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_secret_stamps_two_factor_confirmed_at(): void
    {
        $admin = PlatformAdmin::factory()->create(['two_factor_confirmed_at' => null]);

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->refresh();

        $this->assertSame('JBSWY3DPEHPK3PXP', $admin->getAppAuthenticationSecret());
        $this->assertNotNull($admin->two_factor_confirmed_at);
    }

    public function test_clearing_the_secret_nulls_two_factor_confirmed_at(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $admin->saveAppAuthenticationSecret(null);
        $admin->refresh();

        $this->assertNull($admin->getAppAuthenticationSecret());
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    public function test_holder_name_is_the_admin_email(): void
    {
        $admin = PlatformAdmin::factory()->create(['email' => 'someone@example.test']);

        $this->assertSame('someone@example.test', $admin->getAppAuthenticationHolderName());
    }

    public function test_recovery_codes_round_trip(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $admin->saveAppAuthenticationRecoveryCodes(['hash-one', 'hash-two']);
        $admin->refresh();

        $this->assertSame(['hash-one', 'hash-two'], $admin->getAppAuthenticationRecoveryCodes());

        $admin->saveAppAuthenticationRecoveryCodes(null);
        $admin->refresh();

        $this->assertNull($admin->getAppAuthenticationRecoveryCodes());
    }
}
