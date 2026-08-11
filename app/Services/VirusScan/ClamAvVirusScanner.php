<?php

namespace App\Services\VirusScan;

use App\Enums\DocumentScanStatus;
use App\ValueObjects\VirusScanResult;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * ClamAvVirusScanner — Mission 1C (Security Validation, Activation &
 * Staging Proof), section 15: the real, daemon-backed VirusScanner
 * implementation FakeVirusScanner's own docblock always said could be
 * added "purely additively" later. Speaks clamd's native INSTREAM
 * protocol directly (connect, `zINSTREAM\0`, length-prefixed chunks,
 * a zero-length terminator chunk, read the reply) — no shell-exec, no
 * clamdscan subprocess, no temp-file round-trip. Streams the
 * document's bytes straight from its Storage disk to the daemon over
 * a Unix domain socket or a TCP connection, matching how a real
 * deployment would run clamd as a co-located sidecar (see
 * docs/security/mission-1c-malware-scanner-decision.md for the
 * provider decision this implementation follows).
 *
 * Deliberately NOT bound as the container's default VirusScanner (see
 * AppServiceProvider) — that stays FakeVirusScanner everywhere a real
 * clamd daemon isn't guaranteed to exist: every CI runner, every other
 * engineer's local machine, and — per this mission's own
 * environment-constraints doc — the real AWS staging environment,
 * which has no clamd sidecar deployed yet. Flipping the default
 * binding is the deployment decision for whoever actually provisions
 * that sidecar, not something this class does unilaterally.
 */
class ClamAvVirusScanner implements VirusScanner
{
    private const CHUNK_SIZE = 8192;

    public function __construct(
        private readonly string $socket,
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    public function scan(string $storageDisk, string $storagePath): VirusScanResult
    {
        try {
            $contents = Storage::disk($storageDisk)->get($storagePath);
        } catch (Throwable $e) {
            return new VirusScanResult(status: DocumentScanStatus::Failed, detail: "Could not read [{$storagePath}] from disk [{$storageDisk}]: {$e->getMessage()}");
        }

        if ($contents === null) {
            return new VirusScanResult(status: DocumentScanStatus::Failed, detail: "File not found on disk [{$storageDisk}] at path [{$storagePath}].");
        }

        try {
            $response = $this->sendInstream($contents);
        } catch (RuntimeException $e) {
            return new VirusScanResult(status: DocumentScanStatus::Failed, detail: $e->getMessage());
        }

        return $this->interpretResponse($response);
    }

    private function sendInstream(string $contents): string
    {
        $connection = @stream_socket_client($this->socket, $errno, $errstr, $this->timeoutSeconds);

        if ($connection === false) {
            throw new RuntimeException("Could not connect to clamd at [{$this->socket}]: {$errstr} ({$errno}).");
        }

        stream_set_timeout($connection, (int) $this->timeoutSeconds);

        try {
            fwrite($connection, "zINSTREAM\0");

            if ($contents !== '') {
                foreach (str_split($contents, self::CHUNK_SIZE) as $chunk) {
                    fwrite($connection, pack('N', strlen($chunk)).$chunk);
                }
            }

            // The zero-length chunk is the INSTREAM end-of-stream marker.
            fwrite($connection, pack('N', 0));

            $response = '';
            while (! feof($connection)) {
                $response .= fread($connection, 4096);
            }

            return trim($response, "\0 \n\r");
        } finally {
            fclose($connection);
        }
    }

    /**
     * clamd's INSTREAM reply is one of:
     *   "stream: OK"
     *   "stream: <SignatureName> FOUND"
     *   "stream: <message> ERROR"
     */
    private function interpretResponse(string $response): VirusScanResult
    {
        if (str_ends_with($response, 'OK')) {
            return new VirusScanResult(status: DocumentScanStatus::Clean);
        }

        if (str_ends_with($response, 'FOUND')) {
            $threatName = trim(str_replace(['stream:', 'FOUND'], '', $response)) ?: null;

            return new VirusScanResult(status: DocumentScanStatus::Infected, threatName: $threatName, detail: $response);
        }

        return new VirusScanResult(status: DocumentScanStatus::Failed, detail: $response);
    }
}
