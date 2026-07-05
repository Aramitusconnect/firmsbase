<?php

namespace App\ValueObjects;

/**
 * SignatureEvidenceSnapshot — the assembled shape SignatureCertificateService
 * serializes into signature_certificates.certificate_data_json at
 * generation time. Every field here is read from data that already
 * existed and was already immutable (signature_events, the recipients'
 * cached consent/signature fields, and the document_hashes row) — the
 * certificate documents evidence, it does not create new evidentiary
 * claims at generation time.
 */
final readonly class SignatureEvidenceSnapshot
{
    /**
     * @param array<int, array{
     *   recipientUuid: string,
     *   signerName: ?string,
     *   signerEmail: string,
     *   textVersion: ?string,
     *   consentedAt: ?string,
     *   signedAt: ?string,
     * }> $recipients
     * @param array<int, array{eventType: string, occurredAt: string}> $eventTrail
     */
    public function __construct(
        public int $signatureRequestId,
        public string $documentHashValue,
        public string $hashAlgorithm,
        public array $recipients,
        public array $eventTrail,
        public \DateTimeInterface $assembledAt,
    ) {
    }
}
