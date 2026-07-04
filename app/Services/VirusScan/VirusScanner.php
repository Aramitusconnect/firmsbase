<?php

namespace App\Services\VirusScan;

use App\ValueObjects\VirusScanResult;

/**
 * VirusScanner — the abstraction every document scan goes through.
 * No production implementation ships in Phase 4 requiring a real
 * ClamAV daemon; FakeVirusScanner is the only implementation, used by
 * both ScanDocumentJob and every test. A real daemon-backed
 * implementation can be added later purely additively — nothing that
 * depends on this interface needs to change.
 */
interface VirusScanner
{
    public function scan(string $storageDisk, string $storagePath): VirusScanResult;
}
