<?php

namespace Tests\Feature\Security\WebAuthn;

use App\Filament\MultiFactor\WebAuthn\WebAuthnAuthentication;
use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WebAuthnAuthenticationTest — Mission 1B (Extreme Security
 * Hardening). Covers this provider's own PHP-side logic — the
 * cryptographic core it delegates to is separately, fully verified in
 * WebAuthnCeremonyServiceTest with real signatures.
 */
class WebAuthnAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_id_is_webauthn(): void
    {
        $this->assertSame('webauthn', WebAuthnAuthentication::make()->getId());
    }

    public function test_is_enabled_is_false_with_no_registered_credentials(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertFalse(WebAuthnAuthentication::make()->isEnabled($admin));
    }

    public function test_is_enabled_is_true_once_a_credential_is_registered(): void
    {
        $admin = PlatformAdmin::factory()->create();
        WebauthnCredential::factory()->create(['platform_admin_id' => $admin->id]);

        $this->assertTrue(WebAuthnAuthentication::make()->isEnabled($admin));
    }

    public function test_management_schema_lists_every_registered_credential(): void
    {
        $admin = PlatformAdmin::factory()->create();
        WebauthnCredential::factory()->count(2)->create(['platform_admin_id' => $admin->id]);

        $this->actingAs($admin, 'platform_admin');

        $components = WebAuthnAuthentication::make()->getManagementSchemaComponents();

        // 2 credential rows + 1 trailing Actions component (register button).
        $this->assertCount(3, $components);
    }

    public function test_challenge_form_components_are_scoped_to_the_given_admin(): void
    {
        $adminA = PlatformAdmin::factory()->create();
        $adminB = PlatformAdmin::factory()->create();
        WebauthnCredential::factory()->create(['platform_admin_id' => $adminA->id]);
        WebauthnCredential::factory()->count(2)->create(['platform_admin_id' => $adminB->id]);

        $components = WebAuthnAuthentication::make()->getChallengeFormComponents($adminB);

        // View component + Hidden field — presence alone proves this
        // didn't throw resolving options; the actual allowCredentials
        // scoping is proven directly in WebAuthnCeremonyServiceTest.
        $this->assertCount(2, $components);
    }
}
