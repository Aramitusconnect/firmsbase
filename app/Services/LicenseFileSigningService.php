<?php

namespace App\Services;

use App\ValueObjects\SignedLicensePayload;

/**
 * LicenseFileSigningService — platform-side only signing of the
 * offline license artifact. PHP-native only (project rule: PHP-native
 * signing/verification, prefer sodium if available) — uses PHP's
 * built-in sodium extension (ext-sodium, bundled with PHP core since
 * 7.2, no package/SDK dependency) for Ed25519 detached signatures.
 * Ed25519 signing is deterministic (same message + same secret key
 * always produces the same signature bytes), which keeps tests
 * deterministic and fully local — no network call of any kind.
 *
 * Key distribution/storage is explicitly OUT OF SCOPE for this
 * foundation phase: generateKeypair() exists so tests and callers can
 * produce a keypair locally, but where the platform's real signing
 * secret key is held long-term (a config value, an environment
 * variable, a dedicated secrets store) is a decision for whichever
 * later phase actually operationalizes issuing license files to real
 * dedicated/private customers — deliberately not decided here since it
 * would require touching config/ (a protected surface in Phase 16).
 */
class LicenseFileSigningService
{
    private const ALGORITHM = 'ed25519';

    /**
     * @return array{publicKey: string, secretKey: string} both base64-encoded
     */
    public function generateKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'publicKey' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'secretKey' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        ];
    }

    /**
     * @return array{signedPayloadJson: string, signature: string, algorithm: string}
     */
    public function sign(SignedLicensePayload $payload, string $secretKeyBase64): array
    {
        $canonicalJson = $payload->toCanonicalJson();
        $secretKey = base64_decode($secretKeyBase64, true);

        if ($secretKey === false) {
            throw new \InvalidArgumentException('secretKeyBase64 is not valid base64.');
        }

        $signature = sodium_crypto_sign_detached($canonicalJson, $secretKey);

        return [
            'signedPayloadJson' => $canonicalJson,
            'signature' => base64_encode($signature),
            'algorithm' => self::ALGORITHM,
        ];
    }
}
