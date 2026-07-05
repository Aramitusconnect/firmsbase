<?php

namespace App\ValueObjects;

use App\Enums\SignatureCertificateStatus;

final readonly class CertificateGenerationResult
{
    public function __construct(
        public int $signatureCertificateId,
        public SignatureCertificateStatus $status,
        public int $documentHashId,
        public \DateTimeInterface $generatedAt,
    ) {
    }
}
