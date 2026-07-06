<?php

namespace App\Services;

use App\Enums\LicenseStatus;
use App\Enums\LicenseValidationEventType;
use App\Enums\LicenseValidationResult;
use App\Enums\SeatClass;
use App\Models\LicenseFile;
use App\Models\LicenseValidationEvent;
use App\ValueObjects\LicenseValidationOutcome;
use App\ValueObjects\SignedLicensePayload;

/**
 * LicenseFileValidationService — validates a signed license file
 * ENTIRELY OFFLINE (project rule 12 / approved license design: must
 * never require outbound connectivity). The public key is supplied by
 * the caller (bundled with the application itself in a real
 * deployment, never fetched over the network) — this service makes no
 * HTTP/DNS/socket call of any kind.
 *
 * Every call writes exactly one license_validation_events row,
 * regardless of outcome. Event-type semantics (all six approved cases
 * given a distinct, non-overlapping meaning):
 *   - RevokedCheck: the license_files row itself has been revoked —
 *     checked FIRST, before signature/content, and always wins.
 *   - SignatureInvalid: the Ed25519 signature does not verify against
 *     the supplied public key.
 *   - Validated: signature verifies AND every content check (license
 *     key match, deployment mode match, well-formed allowed_* fields)
 *     passes AND the license is not expired. Also used, with
 *     result=Invalid, when signature verifies but a CONTENT check
 *     fails (license_key mismatch, malformed payload) — there is no
 *     separate "content_invalid" case in the approved enum, so this
 *     event type is read as "the validation pipeline reached a content
 *     verdict," with `result`/`detail` carrying the actual outcome.
 *   - EnteredGrace: the FIRST validate() call that discovers the
 *     license is past expires_at but still within grace_period_days —
 *     this is the one-time transition; also the moment
 *     FirmLicenseCommercialService::changeStatus(GracePeriod) is
 *     called (existing Phase 6 service, untouched).
 *   - Expired: a SUBSEQUENT validate() call while the license is still
 *     within its grace window (no new transition, already GracePeriod).
 *   - GraceExpired: past both expires_at and grace_period_days —
 *     result Invalid, transitions the linked license status to
 *     Restricted (never a hard block — project rule 14).
 *
 * Never bricks an instance: even a fully Invalid/GraceExpired result
 * only ever drives the EXISTING LicenseStatus vocabulary
 * (GracePeriod/Restricted) via the EXISTING
 * FirmLicenseCommercialService — it never deletes data, never throws
 * to halt the request, and never blocks legal-data access itself
 * (that enforcement, if any, belongs to whichever policy layer reads
 * LicenseStatus — out of this phase's scope to build).
 */
class LicenseFileValidationService
{
    public function __construct(private readonly FirmLicenseCommercialService $firmLicenseCommercial)
    {
    }

    public function validate(
        LicenseFile $licenseFile,
        string $publicKeyBase64,
        string $linkedLicenseKey,
        \DateTimeInterface $now = new \DateTimeImmutable(),
    ): LicenseValidationOutcome {
        if ($licenseFile->isRevoked()) {
            return $this->record($licenseFile, LicenseValidationEventType::RevokedCheck, LicenseValidationResult::Invalid, $now, 'License file has been revoked.');
        }

        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($licenseFile->signature, true);

        if ($publicKey === false || $signature === false
            || ! sodium_crypto_sign_verify_detached($signature, $licenseFile->signed_payload, $publicKey)
        ) {
            return $this->record($licenseFile, LicenseValidationEventType::SignatureInvalid, LicenseValidationResult::Invalid, $now, 'Signature verification failed.');
        }

        try {
            $payload = SignedLicensePayload::fromArray(json_decode($licenseFile->signed_payload, true, flags: JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return $this->record($licenseFile, LicenseValidationEventType::Validated, LicenseValidationResult::Invalid, $now, 'Signed payload is malformed.');
        }

        $contentIssue = $this->firstContentIssue($licenseFile, $payload, $linkedLicenseKey);

        if ($contentIssue !== null) {
            return $this->record($licenseFile, LicenseValidationEventType::Validated, LicenseValidationResult::Invalid, $now, $contentIssue);
        }

        $graceExpiresAt = (clone $payload->expiresAt)->modify("+{$payload->gracePeriodDays} days");

        if ($now < $payload->expiresAt) {
            return $this->record($licenseFile, LicenseValidationEventType::Validated, LicenseValidationResult::Valid, $now);
        }

        if ($now < $graceExpiresAt) {
            $alreadyInGrace = $licenseFile->firmLicense?->license_status === LicenseStatus::GracePeriod;

            if (! $alreadyInGrace && $licenseFile->firmLicense) {
                $this->firmLicenseCommercial->changeStatus($licenseFile->firmLicense, LicenseStatus::GracePeriod, 'License expired; entering grace period.');
            }

            $eventType = $alreadyInGrace ? LicenseValidationEventType::Expired : LicenseValidationEventType::EnteredGrace;

            return $this->record($licenseFile, $eventType, LicenseValidationResult::Grace, $now, 'License is within its grace period.');
        }

        if ($licenseFile->firmLicense
            && $licenseFile->firmLicense->license_status !== LicenseStatus::Restricted
        ) {
            $this->firmLicenseCommercial->changeStatus($licenseFile->firmLicense, LicenseStatus::Restricted, 'License grace period has lapsed.');
        }

        return $this->record($licenseFile, LicenseValidationEventType::GraceExpired, LicenseValidationResult::Invalid, $now, 'License grace period has lapsed.');
    }

    private function firstContentIssue(LicenseFile $licenseFile, SignedLicensePayload $payload, string $linkedLicenseKey): ?string
    {
        if ($payload->licenseKey !== $linkedLicenseKey) {
            return 'license_key does not match the linked firm_license/org_license.';
        }

        if ($payload->deploymentMode !== $licenseFile->deployment_mode) {
            return 'deployment_mode in the signed payload does not match this license file record.';
        }

        if (empty($payload->allowedModules)) {
            return 'allowed_modules must not be empty.';
        }

        if ($payload->allowedUsers < 1) {
            return 'allowed_users must be at least 1.';
        }

        foreach ($payload->allowedSeatClasses as $seatClass) {
            if (SeatClass::tryFrom($seatClass) === null) {
                return "allowed_seat_classes contains an unrecognized seat class: {$seatClass}.";
            }
        }

        if (empty($payload->allowedPracticeAreas)) {
            return 'allowed_practice_areas must not be empty.';
        }

        if (trim($payload->supportLevel) === '') {
            return 'support_level must not be empty.';
        }

        if (trim($payload->renewalRules) === '') {
            return 'renewal_rules must not be empty.';
        }

        return null;
    }

    private function record(
        LicenseFile $licenseFile,
        LicenseValidationEventType $eventType,
        LicenseValidationResult $result,
        \DateTimeInterface $validatedAt,
        ?string $detail = null,
    ): LicenseValidationOutcome {
        LicenseValidationEvent::create([
            'license_file_id' => $licenseFile->id,
            'firm_id' => $licenseFile->firm_id,
            'event_type' => $eventType,
            'result' => $result,
            'detail' => $detail,
            'validated_at' => $validatedAt,
        ]);

        return new LicenseValidationOutcome($result, $eventType, $detail);
    }
}
