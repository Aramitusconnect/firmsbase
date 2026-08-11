<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\DocumentScanStatus;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
use App\Models\PlatformAdmin;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
 *   - stored and scanned via the existing VirusScanner interface
 *     (FakeVirusScanner in every environment without a real daemon,
 *     same as every other upload path in this codebase) BEFORE a
 *     single byte of its content is parsed
 *   - malformed rows (wrong column count) are flagged per-row, never
 *     abort the whole batch
 *   - every parsed cell is formula-injection-neutralized on ingestion
 *     (a leading =/+/-/@ is quote-prefixed) — defensive even before any
 *     export/admin-review surface exists, since raw_data is exactly
 *     what such a surface would eventually render
 *   - the quarantine copy is deleted immediately after parsing — the
 *     staged DirectoryImportRow rows are the durable record, not a
 *     retained file
 */
class MarketplaceCsvIngestionService
{
    private const MAX_SIZE_BYTES = 26_214_400; // 25 MB — matches DocumentUploadPolicyService's own convention.

    private const EXPECTED_HEADERS = [
        'legal_name', 'display_name', 'phone', 'website', 'public_email',
        'description', 'city', 'state', 'postal_code', 'founding_year',
    ];

    public function __construct(private readonly VirusScanner $virusScanner) {}

    public function ingest(UploadedFile $file, PlatformAdmin $admin): DirectoryImportBatch
    {
        $originalName = $file->getClientOriginalName();
        $this->assertUploadAllowed($originalName, (int) $file->getSize());

        $storagePath = $file->storeAs('marketplace-imports/quarantine', uniqid('', true).'-'.$originalName, 'local');

        try {
            $scanResult = $this->virusScanner->scan('local', $storagePath);

            if ($scanResult->status === DocumentScanStatus::Infected) {
                throw new \RuntimeException("Uploaded CSV failed virus scan: {$scanResult->detail}");
            }

            if ($scanResult->status === DocumentScanStatus::Failed) {
                throw new \RuntimeException('Uploaded CSV could not be scanned; rejected rather than accepted unscanned.');
            }

            $rows = $this->parseCsv(Storage::disk('local')->path($storagePath));
        } finally {
            Storage::disk('local')->delete($storagePath);
        }

        $batch = DirectoryImportBatch::create([
            'created_by_platform_admin_id' => $admin->id,
            'original_filename' => $originalName,
            'status' => DirectoryImportBatchStatus::Staged,
            'total_rows' => count($rows),
        ]);

        foreach ($rows as $index => $row) {
            DirectoryImportRow::create([
                'directory_import_batch_id' => $batch->id,
                'row_number' => $index + 1,
                'raw_data' => $row['data'],
                'status' => $row['malformed'] ? DirectoryImportRowStatus::Invalid : DirectoryImportRowStatus::Pending,
                'errors' => $row['malformed'] ? ['Row has a different number of columns than the header row.'] : null,
            ]);
        }

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
     * @return array<int, array{data: array<string, string>, malformed: bool}>
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

        $rows = [];
        while (($fields = fgetcsv($handle)) !== false) {
            if ($fields === [null] || $fields === ['']) {
                continue; // trailing blank line
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
