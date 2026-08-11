<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Services\DocumentSecurityService;
use App\Services\VirusScan\ClamAvVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ClamAvVirusScannerLocalProofTest — Mission 1C (Security Validation,
 * Activation & Staging Proof), section 15: "prove the malware-scanning
 * abstraction against a real scanning engine, not just
 * FakeVirusScanner." Talks to an actual `clamd` daemon over its native
 * INSTREAM protocol, loaded with ClamAV's real, official signature
 * database (installed via `apt-get install clamav-daemon`, standard
 * Ubuntu packaging — no custom/synthetic signatures) — the industry-
 * standard EICAR test string is a real, officially recognized test
 * signature in that database, not a fixture only this test recognizes.
 *
 * This test is environment-conditional by design: it skips cleanly
 * (not a failure) wherever no clamd is reachable at
 * `services.clamav.socket` — every CI runner, every other engineer's
 * machine, and the real AWS staging environment all fall into that
 * category today (see mission-1c-environment-constraints.md and
 * mission-1c-malware-scanner-decision.md). Skipping here is itself
 * correct: it proves the abstraction is genuinely optional/additive,
 * exactly as FakeVirusScanner's own docblock always promised.
 */
class ClamAvVirusScannerLocalProofTest extends TestCase
{
    use RefreshDatabase;

    private const EICAR_STRING = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private const SOCKET = 'unix:///var/run/clamav/clamd.ctl';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.clamav.socket', self::SOCKET);

        if (! $this->clamdIsReachable()) {
            $this->markTestSkipped('No clamd daemon reachable at '.self::SOCKET.' in this environment — ClamAvVirusScanner remains untested here by design (see class docblock).');
        }
    }

    public function test_the_real_engine_flags_the_industry_standard_eicar_test_string_as_infected(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eicar-test.pdf', self::EICAR_STRING);

        $result = app(ClamAvVirusScanner::class)->scan('local', 'documents/eicar-test.pdf');

        $this->assertSame(DocumentScanStatus::Infected, $result->status);
        $this->assertNotNull($result->threatName);
        $this->assertStringContainsStringIgnoringCase('eicar', $result->threatName);
    }

    public function test_the_real_engine_clears_an_ordinary_file_as_clean(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/ordinary.pdf', 'This is a perfectly ordinary, harmless document with no malware signature.');

        $result = app(ClamAvVirusScanner::class)->scan('local', 'documents/ordinary.pdf');

        $this->assertSame(DocumentScanStatus::Clean, $result->status);
        $this->assertNull($result->threatName);
    }

    public function test_a_missing_file_is_reported_as_a_failed_scan_not_a_false_clean(): void
    {
        Storage::fake('local');

        $result = app(ClamAvVirusScanner::class)->scan('local', 'documents/does-not-exist.pdf');

        $this->assertSame(DocumentScanStatus::Failed, $result->status);
    }

    /**
     * End-to-end proof: the real engine's Infected verdict, run through
     * DocumentSecurityService::applyScanResult() exactly as
     * ScanDocumentJob would, genuinely quarantines the document
     * (Rejected + not usable) — not merely a raw scanner-in-isolation
     * assertion.
     */
    public function test_an_eicar_result_from_the_real_engine_genuinely_quarantines_the_document(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/eicar-test.pdf', self::EICAR_STRING);

        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'storage_disk' => 'local',
            'storage_path' => 'documents/eicar-test.pdf',
            'scan_status' => DocumentScanStatus::Pending,
            'status' => DocumentStatus::Uploaded,
        ]));

        $result = app(ClamAvVirusScanner::class)->scan($document->storage_disk, $document->storage_path);
        $scanned = $this->runWithFirmContext($firm, fn () => app(DocumentSecurityService::class)->applyScanResult($document, $result));

        $this->assertSame(DocumentScanStatus::Infected, $scanned->scan_status);
        $this->assertSame(DocumentStatus::Rejected, $scanned->status);
        $this->assertFalse($scanned->isUsable());
        $this->assertNotNull($scanned->rejected_reason);
    }

    private function clamdIsReachable(): bool
    {
        $connection = @stream_socket_client(self::SOCKET, $errno, $errstr, 2.0);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
