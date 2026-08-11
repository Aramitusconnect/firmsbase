<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;

/**
 * WebAuthnTestFixtureFactory — Mission 1B (Extreme Security Hardening).
 * Builds REAL, cryptographically valid WebAuthn registration/
 * authentication responses for tests — a real EC P-256 key pair (via
 * PHP's own openssl extension), a real CBOR-encoded COSE public key
 * and "none"-format attestation object (via the exact
 * spomky-labs/cbor-php library web-auth/webauthn-lib itself depends
 * on), and a real ECDSA signature over the exact byte sequence the
 * WebAuthn spec defines (authenticatorData || SHA-256(clientDataJSON)).
 *
 * This exists so WebAuthnCeremonyServiceTest exercises the REAL,
 * unmodified vendor validator classes end-to-end — proving this
 * application's integration is correct — rather than mocking around
 * the cryptographic verification step it exists to prove.
 */
class WebAuthnTestFixtureFactory
{
    private OpenSSLAsymmetricKey $privateKey;

    private string $x;

    private string $y;

    public string $credentialId;

    public function __construct()
    {
        $this->privateKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $details = openssl_pkey_get_details($this->privateKey);
        $this->x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->credentialId = random_bytes(32);
    }

    public function cosePublicKeyCbor(): string
    {
        // COSE_Key map for an ES256 EC2 public key (RFC 8152 §7/§13.1.1):
        // 1 (kty) => 2 (EC2), 3 (alg) => -7 (ES256), -1 (crv) => 1 (P-256),
        // -2 (x) => raw x-coordinate, -3 (y) => raw y-coordinate.
        $map = MapObject::create([
            MapItem::create(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)),
            MapItem::create(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)),
            MapItem::create(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)),
            MapItem::create(NegativeIntegerObject::create(-2), ByteStringObject::create($this->x)),
            MapItem::create(NegativeIntegerObject::create(-3), ByteStringObject::create($this->y)),
        ]);

        return (string) $map;
    }

    /**
     * @param  bool  $withAttestedCredentialData  true for a registration
     *                                            ceremony's authData,
     *                                            false for an
     *                                            authentication
     *                                            ceremony's.
     */
    public function authenticatorData(string $rpId, int $signCount, bool $withAttestedCredentialData): string
    {
        $rpIdHash = hash('sha256', $rpId, true);

        // Flag bits: bit 0 = User Present, bit 2 = User Verified,
        // bit 6 = Attested credential data included.
        $flags = 0x01 | 0x04 | ($withAttestedCredentialData ? 0x40 : 0x00);

        $data = $rpIdHash.chr($flags).pack('N', $signCount);

        if ($withAttestedCredentialData) {
            $aaguid = str_repeat("\0", 16);
            $credentialIdLength = pack('n', strlen($this->credentialId));
            $data .= $aaguid.$credentialIdLength.$this->credentialId.$this->cosePublicKeyCbor();
        }

        return $data;
    }

    public function clientDataJson(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $this->base64url($challenge),
            'origin' => $origin,
        ]);
    }

    public function attestationObjectCbor(string $authenticatorData): string
    {
        $map = MapObject::create([
            MapItem::create(TextStringObject::create('fmt'), TextStringObject::create('none')),
            MapItem::create(TextStringObject::create('attStmt'), MapObject::create([])),
            MapItem::create(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData)),
        ]);

        return (string) $map;
    }

    public function sign(string $authenticatorData, string $clientDataJson): string
    {
        $toSign = $authenticatorData.hash('sha256', $clientDataJson, true);
        openssl_sign($toSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signature;
    }

    /**
     * A full registration (attestation) response, as the browser's
     * `navigator.credentials.create()` would return it, JSON-encoded
     * exactly as this app's frontend would POST it.
     */
    public function registrationResponseJson(string $rpId, string $challenge, string $origin): string
    {
        $authenticatorData = $this->authenticatorData($rpId, 0, withAttestedCredentialData: true);
        $clientDataJson = $this->clientDataJson('webauthn.create', $challenge, $origin);
        $attestationObject = $this->attestationObjectCbor($authenticatorData);

        return json_encode([
            'id' => $this->base64url($this->credentialId),
            'rawId' => $this->base64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64url($clientDataJson),
                'attestationObject' => $this->base64url($attestationObject),
                'transports' => ['internal'],
            ],
        ]);
    }

    /**
     * A full authentication (assertion) response.
     */
    public function authenticationResponseJson(string $rpId, string $challenge, string $origin, int $signCount = 1): string
    {
        $authenticatorData = $this->authenticatorData($rpId, $signCount, withAttestedCredentialData: false);
        $clientDataJson = $this->clientDataJson('webauthn.get', $challenge, $origin);
        $signature = $this->sign($authenticatorData, $clientDataJson);

        return json_encode([
            'id' => $this->base64url($this->credentialId),
            'rawId' => $this->base64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64url($clientDataJson),
                'authenticatorData' => $this->base64url($authenticatorData),
                'signature' => $this->base64url($signature),
                'userHandle' => null,
            ],
        ]);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
