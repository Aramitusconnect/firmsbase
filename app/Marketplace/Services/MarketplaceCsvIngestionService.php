<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\DocumentScanStatus;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * MarketplaceCsvIngestionService — Mission 2 (MyAttorney Marketplace
 * Core), sections 53-55. Genuinely new (confirmed by repository audit:
 * only CSV *export* writers existed anywhere before this checkpoint) —
 * the only place a raw uploaded CSV file becomes a DirectoryImportBatch
 * with staged DirectoryImportRow rows. Treats the file as fully
 * untrusted input end to end (section 55):
 *
 *   - extension/size allowlist (own, narrow allowlist — .csv only,
 *     never widening DocumentUploadPolicyService's own document-type
 *     allowlist, which does not and should not include .csv)
 *   - a bounded content-sample (never the whole file) is inspected for
 *     NUL bytes, invalid UTF-8, and known binary/executable magic-byte
 *     signatures before a single row is parsed — see
 *     assertContentLooksLikeText()'s own docblock (MyAttorney final
 *     hardening mission, finding 2)
 *   - stored and scanned via the existing VirusScanner interface
 *     (FakeVirusScanner in every environment without a real daemon,
 *     same as every other upload path in this codebase) BEFORE a
 *     single byte of its content is parsed
 *   - the quarantine storage filename NEVER embeds the raw,
 *     attacker-controlled original filename as a literal path
 *     component — see sanitizeFilenameComponent()'s own docblock
 *     (MyAttorney final hardening mission, finding 5: a real,
 *     confirmed path-traversal write primitive existed here before
 *     this fix)
 *   - a hard row-count ceiling (config('marketplace.import_max_rows'))
 *     bounds worst-case memory and row creation regardless of the
 *     25MB byte cap (finding 3) — enforced during parsing, never after
 *     loading the whole file
 *   - malformed rows (wrong column count) or excessively long rows are
 *     flagged per-row, never abort the whole batch
 *   - every parsed cell is formula-injection-neutralized on ingestion
 *     (a leading =/+/-/@ is quote-prefixed) — defensive even before any
 *     export/admin-review surface exists, since raw_data is exactly
 *     what such a surface would eventually render
 *   - the quarantine copy is deleted immediately after parsing — the
 *     staged DirectoryImportRow rows are the durable record, not a
 *     retained file
 *   - a successful ingest is audited (finding 4) via the canonical
 *     PlatformAdminAuditEventRecorder — never a raw CSV row, never PII,
 *     just counts/filename/actor
 */
class MarketplaceCsvIngestionService
{
    private const MAX_SIZE_BYTES = 26_214_400; // 25 MB — matches DocumentUploadPolicyService's own convention.

    private const CONTENT_SAMPLE_BYTES = 8192; // Bounded — never the whole (up to 25MB) file.

    private const MAX_LINE_BYTES = 65_536; // 64KB — far larger than any legitimate directory row, bounds one pathological line.

    /**
     * Known binary/executable magic-byte signatures that must never be
     * accepted under a `.csv` name, regardless of what the browser
     * claimed as the MIME type (client-supplied, spoofable — never
     * trusted here). CSV itself has no universal magic byte (it is
     * plain text), so this list exists purely to reject KNOWN-BINARY
     * formats masquerading as .csv, not to positively assert "this is
     * definitely CSV" (that is what the header/row parsing below
     * already does).
     */
    private const BINARY_SIGNATURES = [
        'MZ' => 'Windows executable (PE/MZ)',
        "\x7fELF" => 'Linux executable (ELF)',
        '%PDF' => 'PDF document',
        "\x89PNG" => 'PNG image',
        'GIF8' => 'GIF image',
        "\xFF\xD8\xFF" => 'JPEG image',
        "PK\x03\x04" => 'ZIP-family archive (zip/docx/xlsx/jar)',
        '#!' => 'script with a shebang line',
        "\xCA\xFE\xBA\xBE" => 'Java class file',
    ];

    private const EXPECTED_HEADERS = [
        'legal_name', 'display_name', 'phone', 'website', 'public_email',
        'description', 'city', 'state', 'postal_code', 'founding_year',
    ];

    public function __construct(
        private readonly VirusScanner $virusScanner,
        private readonly PlatformAdminAuditEventRecorder $audit = new PlatformAdminAuditEventRecorder,
    ) {}

    public function ingest(UploadedFile $file, PlatformAdmin $admin): DirectoryImportBatch
    {
        $originalName = $file->getClientOriginalName();
        $this->assertUploadAllowed($originalName, (int) $file->getSize());

        $displayName = $this->sanitizeFilenameComponent($originalName);
        $storagePath = $file->storeAs('marketplace-imports/quarantine', Str::uuid()->toString().'-'.$displayName, 'local');

        try {
            $scanResult = $this->virusScanner->scan('local', $storagePath);

            if ($scanResult->status === DocumentScanStatus::Infected) {
                throw new \RuntimeException("Uploaded CSV failed virus scan: {$scanResult->detail}");
            }

            if ($scanResult->status === DocumentScanStatus::Failed) {
                throw new \RuntimeException('Uploaded CSV could not be scanned; rejected rather than accepted unscanned.');
            }

            $absolutePath = Storage::disk('local')->path($storagePath);
            $this->assertContentLooksLikeText($absolutePath);
            $rows = $this->parseCsv($absolutePath);
        } finally {
            Storage::disk('local')->delete($storagePath);
        }

        $batch = DirectoryImportBatch::create([
            'created_by_platform_admin_id' => $admin->id,
            'original_filename' => $displayName,
            'status' => DirectoryImportBatchStatus::Staged,
            'total_rows' => count($rows),
        ]);

        foreach ($rows as $index => $row) {
            DirectoryImportRow::create([
                'directory_import_batch_id' => $batch->id,
                'row_number' => $index + 1,
                'raw_data' => $row['data'],
                'status' => $row['malformed'] ? DirectoryImportRowStatus::Invalid : DirectoryImportRowStatus::Pending,
                'errors' => $row['malformed'] ? [$row['reason'] ?? 'Row has a different number of columns than the header row.'] : null,
            ]);
        }

        $this->audit->recordPlatformEvent($admin, 'marketplace_import_uploaded', 'marketplace_import', [
            'directory_import_batch_id' => $batch->id,
            'directory_import_batch_uuid' => (string) $batch->uuid,
            'original_filename' => $displayName,
            'size_bytes' => (int) $file->getSize(),
            'total_rows' => $batch->total_rows,
        ]);

        return $batch->fresh();
    }

    private function assertUploadAllowed(string $originalFilename, int $sizeBytes): void
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            throw new \InvalidArgumentException("File extension '.{$extension}' is not allowed — only .csv is accepted.");
        }

        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_SIZE_BYTES) {
            throw new \InvalidArgumentException('File size exceeds the maximum allowed upload size.');
        }
    }

    /**
     * MyAttorney final hardening mission, finding 5. The original
     * client-supplied filename must NEVER be used, even in part, as a
     * literal filesystem path component — confirmed by direct
     * reproduction (League\Flysystem\WhitespacePathNormalizer, the
     * local disk's own path normalizer, only rejects a `..` sequence
     * once it would underflow PAST the disk root; a filename such as
     * "../../../../evil.csv" concatenated into
     * "marketplace-imports/quarantine/<prefix>-../../../../evil.csv"
     * normalizes to "evil.csv" at the disk root — a real escape from
     * the intended quarantine subdirectory, proven via a standalone
     * reproduction against the installed Flysystem version before this
     * fix, not assumed).
     *
     * basename() alone is insufficient: on this platform PHP's
     * basename() does not treat a literal backslash as a separator,
     * but Flysystem's own normalizer converts backslashes to forward
     * slashes before parsing (str_replace('\\', '/', $path)) — a
     * Windows-style "..\\..\\evil.csv" filename would survive a bare
     * basename() call untouched and still traverse once Flysystem
     * normalizes it. This method instead normalizes both separator
     * styles itself, extracts only the final segment, then restricts
     * the result to a conservative, portable character allowlist —
     * anything else (encoded separators, NUL/control bytes, literal
     * ".." sequences that somehow survive) becomes "_". The result can
     * no longer function as a path at all, in either direction.
     */
    private function sanitizeFilenameComponent(string $originalName): string
    {
        $unixStyle = str_replace('\\', '/', $originalName);
        $base = basename($unixStyle);

        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? '';
        $safe = trim($safe, ' ._-');

        return $safe === '' ? 'upload.csv' : mb_substr($safe, 0, 200);
    }

    /**
     * MyAttorney final hardening mission, finding 2. CSV is plain text
     * with no universal magic byte, so this deliberately does NOT
     * assert "the first bytes must equal a fixed signature" — it only
     * rejects content that is clearly NOT plausible CSV/text: a NUL
     * byte anywhere in the sample, invalid UTF-8, or a leading
     * signature matching a KNOWN binary/executable format. Reads only
     * a bounded sample (never the full, up-to-25MB file) — bounded
     * streaming, not a full-file load.
     */
    private function assertContentLooksLikeText(string $absolutePath): void
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded CSV for content inspection.');
        }

        $sample = fread($handle, self::CONTENT_SAMPLE_BYTES);
        fclose($handle);

        if ($sample === false) {
            throw new \RuntimeException('Could not read the uploaded CSV for content inspection.');
        }

        foreach (self::BINARY_SIGNATURES as $signature => $description) {
            if (str_starts_with($sample, $signature)) {
                throw new \InvalidArgumentException("The uploaded file's content does not look like CSV/text (detected: {$description}).");
            }
        }

        if (str_contains($sample, "\0")) {
            throw new \InvalidArgumentException('The uploaded file contains binary (NUL byte) content and cannot be accepted as CSV.');
        }

        // Strip a UTF-8 BOM before the encoding check — a real BOM is
        // valid UTF-8 framing, not invalid content, and parseCsv()
        // already strips it the same way before header matching.
        $withoutBom = preg_replace('/^\xEF\xBB\xBF/', '', $sample) ?? $sample;

        if (! mb_check_encoding($withoutBom, 'UTF-8')) {
            throw new \InvalidArgumentException('The uploaded file is not valid UTF-8 text.');
        }
    }

    /**
     * @return array<int, array{data: array<string, string>, malformed: bool, reason?: string}>
     */
    private function parseCsv(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded CSV for reading.');
        }

        $headerLine = fgets($handle);

        if ($headerLine === false) {
            fclose($handle);

            throw new \InvalidArgumentException('The uploaded CSV is empty.');
        }

        // Strip a UTF-8 BOM if present — a common source of a mangled
        // first header name that would otherwise silently fail the
        // exact-header-match check below.
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        $headers = array_map('trim', str_getcsv($headerLine));

        $missing = array_diff(self::EXPECTED_HEADERS, $headers);
        if ($missing !== []) {
            fclose($handle);

            throw new \InvalidArgumentException('The uploaded CSV is missing required column(s): '.implode(', ', $missing));
        }

        $maxRows = (int) config('marketplace.import_max_rows');
        $rows = [];

        // fgetcsv() (not a manual fgets()-based line reader) so a
        // legitimately quoted multi-line field (e.g. a multi-paragraph
        // description) keeps parsing correctly — PHP's own CSV
        // primitive already reads as many physical lines as one
        // logical record needs, which a hand-rolled line reader would
        // have to reimplement (and risk getting wrong).
        while (($fields = fgetcsv($handle)) !== false) {
            if ($fields === [null] || $fields === ['']) {
                continue; // trailing blank line
            }

            if (count($rows) >= $maxRows) {
                fclose($handle);

                throw new \InvalidArgumentException("The uploaded CSV exceeds the maximum allowed row count ({$maxRows} rows). Split it into smaller files and import each separately.");
            }

            // A pathologically long logical row (e.g. one enormous
            // field) is flagged invalid rather than accepted whole —
            // bounds what ever lands in the durable raw_data column
            // without needing to reimplement fgetcsv()'s own
            // multi-line-aware line reading.
            $rowByteLength = array_sum(array_map('strlen', $fields));
            if ($rowByteLength > self::MAX_LINE_BYTES) {
                $rows[] = ['data' => [], 'malformed' => true, 'reason' => 'Row exceeds the maximum allowed row size.'];

                continue;
            }

            $malformed = count($fields) !== count($headers);
            $data = [];

            foreach ($headers as $i => $header) {
                $data[$header] = $this->neutralizeFormulaInjection(trim((string) ($fields[$i] ?? '')));
            }

            $rows[] = ['data' => $data, 'malformed' => $malformed];
        }

        fclose($handle);

        return $rows;
    }

    private function neutralizeFormulaInjection(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
