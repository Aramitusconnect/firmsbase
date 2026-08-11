<?php

namespace App\Services\WebAuthn;

use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use App\Services\CanonicalUrlService;
use InvalidArgumentException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * WebAuthnCeremonyService — Mission 1B (Extreme Security Hardening).
 * The real, working cryptographic core behind Platform Admin's
 * phishing-resistant authentication factor. Wraps web-auth/webauthn-lib
 * (the standard, independently-maintained PHP WebAuthn implementation
 * — this class never reimplements any cryptographic verification
 * itself, only builds/parses the spec-defined request/response shapes
 * and persists the result) rather than a hand-rolled verifier — the
 * exact reasoning this project already applies to encryption
 * (EncryptionKeyService's own docblock: "Do not invent custom
 * cryptography").
 *
 * Relying Party: the Admin panel's own canonical host
 * (admin.firmsvault.com in production) — a WebAuthn credential is
 * cryptographically bound to the RP ID it was registered against, so
 * this MUST be the exact host the admin authenticates on, never a
 * shared/broader value.
 *
 * Challenge storage: options objects are round-tripped through this
 * class's own serializer (not PHP's native serialize()) between
 * "generate options" and "verify response" — the caller is
 * responsible for stashing/retrieving the returned JSON string
 * (typically in the session) between those two calls.
 */
class WebAuthnCeremonyService
{
    public function __construct(private readonly CanonicalUrlService $canonicalUrlService) {}

    public function relyingPartyId(): string
    {
        return $this->canonicalUrlService->adminHost();
    }

    /**
     * @return array{0: PublicKeyCredentialCreationOptions, 1: string}
     */
    public function creationOptionsFor(PlatformAdmin $admin): array
    {
        $user = PublicKeyCredentialUserEntity::create(
            name: $admin->email,
            id: $admin->uuid,
            displayName: $admin->name,
        );

        $excludeCredentials = $admin->webauthnCredentials()->get()->map(
            fn (WebauthnCredential $credential) => PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: base64_decode($credential->credential_id),
                transports: $credential->transports ?? [],
            )
        )->all();

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $this->rpEntity(),
            user: $user,
            challenge: random_bytes(32),
            pubKeyCredParams: [
                // ES256 then RS256 — the same algorithm preference
                // order CeremonyStepManagerFactory's own default
                // algorithm manager supports, narrowed to exactly those
                // two (no experimental/legacy algorithms offered).
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60_000,
        );

        return [$options, $this->serializer()->serialize($options, 'json')];
    }

    public function verifyRegistration(string $responseJson, string $optionsJson, PlatformAdmin $admin, string $label): WebauthnCredential
    {
        $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $publicKeyCredential = $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');

        if (! ($publicKeyCredential->response instanceof AuthenticatorAttestationResponse)) {
            throw new InvalidArgumentException('Expected a registration (attestation) response.');
        }

        $validator = AuthenticatorAttestationResponseValidator::create($this->ceremonyStepManagerFactory()->creationCeremony());

        $credentialRecord = $validator->check(
            authenticatorAttestationResponse: $publicKeyCredential->response,
            publicKeyCredentialCreationOptions: $options,
            host: $this->relyingPartyId(),
        );

        return $admin->webauthnCredentials()->create([
            'name' => $label,
            'credential_id' => base64_encode($credentialRecord->publicKeyCredentialId),
            'public_key' => base64_encode($credentialRecord->credentialPublicKey),
            'attestation_type' => $credentialRecord->attestationType,
            'transports' => $credentialRecord->transports,
            'aaguid' => $credentialRecord->aaguid->toRfc4122(),
            'sign_count' => $credentialRecord->counter,
            'backup_eligible' => $credentialRecord->backupEligible,
            'backup_status' => $credentialRecord->backupStatus,
        ]);
    }

    /**
     * @return array{0: PublicKeyCredentialRequestOptions, 1: string}
     */
    public function requestOptionsFor(PlatformAdmin $admin): array
    {
        $allowCredentials = $admin->webauthnCredentials()->get()->map(
            fn (WebauthnCredential $credential) => PublicKeyCredentialDescriptor::create(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: base64_decode($credential->credential_id),
                transports: $credential->transports ?? [],
            )
        )->all();

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->relyingPartyId(),
            allowCredentials: $allowCredentials,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 60_000,
        );

        return [$options, $this->serializer()->serialize($options, 'json')];
    }

    /**
     * Verifies a login-time assertion for a SPECIFIC, already-identified
     * admin (this app never offers discoverable/"passwordless" login —
     * WebAuthn is always the second factor after password, mirroring
     * TOTP's own login_form challenge shape) — fail-closed if the
     * asserted credential does not belong to that exact admin.
     */
    public function verifyAuthentication(string $responseJson, string $optionsJson, PlatformAdmin $admin): WebauthnCredential
    {
        $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
        $publicKeyCredential = $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');

        if (! ($publicKeyCredential->response instanceof AuthenticatorAssertionResponse)) {
            throw new InvalidArgumentException('Expected an authentication (assertion) response.');
        }

        $credentialId = base64_encode($publicKeyCredential->rawId);

        /** @var WebauthnCredential|null $stored */
        $stored = $admin->webauthnCredentials()->where('credential_id', $credentialId)->first();

        if ($stored === null) {
            throw new InvalidArgumentException('This credential is not registered to this admin.');
        }

        $credentialRecord = $this->toCredentialRecord($stored);

        $validator = AuthenticatorAssertionResponseValidator::create($this->ceremonyStepManagerFactory()->requestCeremony());

        $verified = $validator->check(
            credentialRecord: $credentialRecord,
            authenticatorAssertionResponse: $publicKeyCredential->response,
            publicKeyCredentialRequestOptions: $options,
            host: $this->relyingPartyId(),
            userHandle: $admin->uuid,
        );

        $stored->forceFill([
            'sign_count' => $verified->counter,
            'backup_eligible' => $verified->backupEligible,
            'backup_status' => $verified->backupStatus,
            'last_used_at' => now(),
        ])->save();

        return $stored;
    }

    private function toCredentialRecord(WebauthnCredential $credential): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: base64_decode($credential->credential_id),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $credential->transports ?? [],
            attestationType: $credential->attestation_type,
            trustPath: new EmptyTrustPath,
            aaguid: Uuid::fromString($credential->aaguid),
            credentialPublicKey: base64_decode($credential->public_key),
            userHandle: $credential->platformAdmin->uuid,
            counter: $credential->sign_count,
            backupEligible: $credential->backup_eligible,
            backupStatus: $credential->backup_status,
        );
    }

    private function rpEntity(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create(
            name: 'FirmsVault Admin',
            id: $this->relyingPartyId(),
        );
    }

    private function ceremonyStepManagerFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$this->canonicalUrlService->adminUrl()]);

        return $factory;
    }

    private function serializer(): SerializerInterface
    {
        // AttestationStatementSupportManager's own constructor already
        // registers NoneAttestationStatementSupport by default — no
        // further wiring needed since only `attestation: 'none'` is
        // ever requested (see creationOptionsFor()).
        return (new WebauthnSerializerFactory(new AttestationStatementSupportManager))->create();
    }
}
