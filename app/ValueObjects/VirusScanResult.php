<?php

namespace App\ValueObjects;

use App\Enums\DocumentScanStatus;

/**
 * VirusScanResult — returned by any VirusScanner implementation.
 * threatName is only ever populated when status is Infected.
 */
final readonly class VirusScanResult
{
    public function __construct(
        public DocumentScanStatus $status,
        public ?string $threatName = null,
        public ?string $detail = null,
    ) {
    }
}
