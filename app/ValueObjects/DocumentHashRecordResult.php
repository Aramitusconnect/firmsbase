<?php

namespace App\ValueObjects;

use App\Enums\HashAlgorithm;

/**
 * DocumentHashRecordResult — the hash VALUE is caller-supplied (see
 * DocumentHashService docblock); this VO is just the shape returned
 * after that caller-supplied value is durably, immutably recorded.
 */
final readonly class DocumentHashRecordResult
{
    public function __construct(
        public int $documentHashId,
        public HashAlgorithm $algorithm,
        public string $hashValue,
        public \DateTimeInterface $recordedAt,
    ) {
    }
}
