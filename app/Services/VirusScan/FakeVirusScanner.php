<?php

namespace App\Services\VirusScan;

use App\Enums\DocumentScanStatus;
use App\ValueObjects\VirusScanResult;

/**
 * FakeVirusScanner — deterministic, no real scanning engine, no
 * daemon, no network or filesystem I/O. Behavior is driven entirely by
 * markers in the storage_path string, so tests can exercise every
 * DocumentScanStatus outcome without any external dependency:
 *   - path contains "eicar" or "infected"  -> Infected
 *   - path contains "scanfail"             -> Failed
 *   - anything else                        -> Clean
 * This is the ONLY VirusScanner implementation in Phase 4 (approved:
 * "Do not require a real ClamAV daemon if unavailable in tests").
 */
class FakeVirusScanner implements VirusScanner
{
    public function scan(string $storageDisk, string $storagePath): VirusScanResult
    {
        $needle = strtolower($storagePath);

        if (str_contains($needle, 'eicar') || str_contains($needle, 'infected')) {
            return new VirusScanResult(
                status: DocumentScanStatus::Infected,
                threatName: 'EICAR-Test-Signature',
                detail: "Malware signature matched for {$storagePath}",
            );
        }

        if (str_contains($needle, 'scanfail')) {
            return new VirusScanResult(
                status: DocumentScanStatus::Failed,
                detail: "Scan engine could not complete for {$storagePath}",
            );
        }

        return new VirusScanResult(status: DocumentScanStatus::Clean);
    }
}
