<?php

namespace Tests\Feature\Security\WebAuthn;

use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use App\Services\CanonicalUrlService;
use App\Services\WebAuthn\WebAuthnCeremonyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\WebAuthnTestFixtureFactory;
use Tests\TestCase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * WebAuthnCeremonyServiceTest — Mission 1B (Extreme Security
 * Hardening). Proves the real, unmodified web-auth/webauthn-lib
 * validator classes accept a genuinely valid registration/
 * authentication round trip built by WebAuthnTestFixtureFactory (real
 * EC P-256 key, real CBOR/COSE encoding, real ECDSA signature) — not a
 * mocked verification step — and that this service's own authorization
 * boundaries (a credential belongs to exactly one admin) hold.
 */
class WebAuthnCeremonyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WebAuthnCeremonyService
    {
        return app(WebAuthnCeremonyService::class);
    }

    private function origin(): string
    {
        return app(CanonicalUrlService::class)->adminUrl();
    }

    public function test_creation_options_exclude_the_admins_already_registered_credentials(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $existing = WebauthnCredential::factory()->create(['platform_admin_id' => $admin->id]);

        [$options] = $this->service()->creationOptionsFor($admin);

        $this->assertInstanceOf(PublicKeyCredentialCreationOptions::class, $options);
        $this->assertCount(1, $options->excludeCredentials);
        $this->assertSame(base64_decode($existing->credential_id), $options->excludeCredentials[0]->id);
    }

    public function test_a_real_registration_ceremony_round_trip_succeeds_and_persists_the_credential(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = $this->service();
        $fixture = new WebAuthnTestFixtureFactory;

        [$options, $optionsJson] = $service->creationOptionsFor($admin);

        $responseJson = $fixture->registrationResponseJson(
            $service->relyingPartyId(),
            $options->challenge,
            $this->origin(),
        );

        $credential = $service->verifyRegistration($responseJson, $optionsJson, $admin, 'My YubiKey');

        $this->assertSame($admin->id, $credential->platform_admin_id);
        $this->assertSame('My YubiKey', $credential->name);
        $this->assertSame(base64_encode($fixture->credentialId), $credential->credential_id);
        $this->assertSame(0, $credential->sign_count);
        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credential->id, 'platform_admin_id' => $admin->id]);
    }

    public function test_a_registration_with_a_forged_origin_is_rejected(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $service = $this->service();
        $fixture = new WebAuthnTestFixtureFactory;

        [$options, $optionsJson] = $service->creationOptionsFor($admin);

        $responseJson = $fixture->registrationResponseJson(
            $service->relyingPartyId(),
            $options->challenge,
            'https://attacker.example',
        );

        $this->expectException(AuthenticatorResponseVerificationException::class);
        $service->verifyRegistration($responseJson, $optionsJson, $admin, 'Forged');

        $this->assertDatabaseCount('webauthn_credentials', 0);
    }

    public function test_a_real_authentication_ceremony_round_trip_succeeds_and_updates_sign_count_and_last_used_at(): void
    {
        [$admin, $credential, $fixture] = $this->registerAFixtureCredential();
        $service = $this->service();

        [$options, $optionsJson] = $service->requestOptionsFor($admin);

        $responseJson = $fixture->authenticationResponseJson(
            $service->relyingPartyId(),
            $options->challenge,
            $this->origin(),
            signCount: 1,
        );

        $verified = $service->verifyAuthentication($responseJson, $optionsJson, $admin);

        $this->assertSame($credential->id, $verified->id);
        $this->assertSame(1, $verified->fresh()->sign_count);
        $this->assertNotNull($verified->fresh()->last_used_at);
    }

    public function test_a_replayed_lower_sign_count_is_rejected_as_a_cloned_authenticator(): void
    {
        [$admin, , $fixture] = $this->registerAFixtureCredential();
        $service = $this->service();

        // First, a real authentication that legitimately advances the
        // counter to 5.
        [$options1, $optionsJson1] = $service->requestOptionsFor($admin);
        $service->verifyAuthentication(
            $fixture->authenticationResponseJson($service->relyingPartyId(), $options1->challenge, $this->origin(), signCount: 5),
            $optionsJson1,
            $admin,
        );

        // A second authentication asserting a LOWER counter than what
        // is already stored — the exact signal of a cloned/duplicated
        // authenticator per the WebAuthn spec — must be rejected.
        [$options2, $optionsJson2] = $service->requestOptionsFor($admin);
        $responseJson = $fixture->authenticationResponseJson($service->relyingPartyId(), $options2->challenge, $this->origin(), signCount: 2);

        $this->expectException(CounterException::class);
        $service->verifyAuthentication($responseJson, $optionsJson2, $admin);
    }

    public function test_a_credential_belonging_to_a_different_admin_is_rejected_before_any_cryptographic_check(): void
    {
        [$adminA, , $fixtureA] = $this->registerAFixtureCredential();
        $adminB = PlatformAdmin::factory()->create();
        $service = $this->service();

        [$optionsForB, $optionsJsonForB] = $service->requestOptionsFor($adminB);

        // Admin A's real, validly-signed assertion, presented against
        // Admin B's identity — must fail closed purely on the
        // "does this credential belong to this admin" lookup, never
        // reaching (or needing) cryptographic verification at all.
        $responseJson = $fixtureA->authenticationResponseJson($service->relyingPartyId(), $optionsForB->challenge, $this->origin());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered to this admin');
        $service->verifyAuthentication($responseJson, $optionsJsonForB, $adminB);
    }

    public function test_request_options_only_allow_the_admins_own_credentials(): void
    {
        $adminA = PlatformAdmin::factory()->create();
        $adminB = PlatformAdmin::factory()->create();
        WebauthnCredential::factory()->create(['platform_admin_id' => $adminA->id]);
        WebauthnCredential::factory()->create(['platform_admin_id' => $adminB->id]);
        WebauthnCredential::factory()->create(['platform_admin_id' => $adminB->id]);

        [$optionsForB] = $this->service()->requestOptionsFor($adminB);

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $optionsForB);
        $this->assertCount(2, $optionsForB->allowCredentials);
    }

    /**
     * @return array{0: PlatformAdmin, 1: WebauthnCredential, 2: WebAuthnTestFixtureFactory}
     */
    private function registerAFixtureCredential(): array
    {
        $admin = PlatformAdmin::factory()->create();
        $service = $this->service();
        $fixture = new WebAuthnTestFixtureFactory;

        [$options, $optionsJson] = $service->creationOptionsFor($admin);
        $responseJson = $fixture->registrationResponseJson($service->relyingPartyId(), $options->challenge, $this->origin());
        $credential = $service->verifyRegistration($responseJson, $optionsJson, $admin, 'Fixture key');

        return [$admin, $credential, $fixture];
    }
}
