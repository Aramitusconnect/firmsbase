<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
use Illuminate\Support\Str;

/**
 * MarketplaceImportValidationService — Mission 2 (MyAttorney
 * Marketplace Core), sections 53-55. Validates and column-maps every
 * still-Pending row of a batch (rows MarketplaceCsvIngestionService
 * already flagged Invalid for a malformed column count are left
 * untouched — never re-validated into a false Valid). A row-level
 * result, never a batch-aborting exception — one bad row must never
 * block every other row in the file.
 */
class MarketplaceImportValidationService
{
    public function validateBatch(DirectoryImportBatch $batch): DirectoryImportBatch
    {
        $validCount = 0;
        $invalidCount = $batch->rows()->where('status', DirectoryImportRowStatus::Invalid->value)->count();

        foreach ($batch->rows()->where('status', DirectoryImportRowStatus::Pending->value)->get() as $row) {
            $this->validateRow($row);

            if ($row->fresh()->status === DirectoryImportRowStatus::Valid) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        $batch->update([
            'status' => DirectoryImportBatchStatus::Validated,
            'valid_rows' => $validCount,
            'invalid_rows' => $invalidCount,
        ]);

        return $batch->fresh();
    }

    public function validateRow(DirectoryImportRow $row): DirectoryImportRow
    {
        $data = $row->raw_data;
        $errors = [];

        $legalName = trim((string) ($data['legal_name'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));

        if ($legalName === '') {
            $errors[] = 'legal_name is required.';
        }

        if ($displayName === '') {
            $errors[] = 'display_name is required.';
        }

        $website = trim((string) ($data['website'] ?? ''));
        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'website is not a valid URL.';
        }

        $foundingYear = trim((string) ($data['founding_year'] ?? ''));
        if ($foundingYear !== '' && (! ctype_digit($foundingYear) || (int) $foundingYear < 1800 || (int) $foundingYear > (int) date('Y'))) {
            $errors[] = 'founding_year is not a plausible year.';
        }

        if ($errors !== []) {
            $row->update(['status' => DirectoryImportRowStatus::Invalid, 'errors' => $errors]);

            return $row->fresh();
        }

        $row->update([
            'status' => DirectoryImportRowStatus::Valid,
            'errors' => null,
            'mapped_data' => [
                'legal_name' => $legalName,
                'display_name' => $displayName,
                'name_normalized' => Str::lower($displayName),
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'website' => $website ?: null,
                'public_email' => trim((string) ($data['public_email'] ?? '')) ?: null,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'city' => trim((string) ($data['city'] ?? '')) ?: null,
                'city_normalized' => Str::lower(trim((string) ($data['city'] ?? ''))) ?: null,
                'state' => trim((string) ($data['state'] ?? '')) ?: null,
                'postal_code' => trim((string) ($data['postal_code'] ?? '')) ?: null,
                'founding_year' => $foundingYear !== '' ? (int) $foundingYear : null,
            ],
        ]);

        return $row->fresh();
    }
}
