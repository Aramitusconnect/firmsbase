<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;

/**
 * MarketplaceImportDuplicateDetectionService — Mission 2 (MyAttorney
 * Marketplace Core), section 52. Multi-signal matching against every
 * EXISTING directory_firms row — normalized name, normalized phone,
 * and website domain, exactly the signal set section 52 names (office/
 * attorney-relationship/license-identifier signals are checkpoint 9's
 * disclosed, deferred scope — this checkpoint covers Firm-level CSV
 * import only; office/attorney bulk import is a future addition, not a
 * re-architecture). A match NEVER auto-merges — the row is marked
 * Duplicate with the candidate recorded, and stays that way until an
 * admin explicitly decides via MarketplaceImportApplyService.
 *
 * matchForMappedData() was extracted (SuperAdmin console
 * professionalization mission, MYAT2) so DirectoryFirmResource's manual
 * "Add Firm" form can reuse the exact same matching logic as a live
 * duplicate warning, instead of re-implementing it against a
 * DirectoryImportRow the manual-entry path doesn't have. detectRow()'s
 * own behavior (including its side effect of updating the row) is
 * unchanged — it now just delegates the matching itself to the new
 * method.
 */
class MarketplaceImportDuplicateDetectionService
{
    public function detectBatch(DirectoryImportBatch $batch): DirectoryImportBatch
    {
        $duplicateCount = 0;

        foreach ($batch->rows()->where('status', DirectoryImportRowStatus::Valid->value)->get() as $row) {
            if ($this->detectRow($row) !== null) {
                $duplicateCount++;
            }
        }

        $batch->update([
            'status' => DirectoryImportBatchStatus::Previewed,
            'duplicate_rows' => $duplicateCount,
        ]);

        return $batch->fresh();
    }

    public function detectRow(DirectoryImportRow $row): ?DirectoryFirm
    {
        $match = $this->matchForMappedData($row->mapped_data ?? []);

        if ($match !== null) {
            $row->update(['status' => DirectoryImportRowStatus::Duplicate, 'duplicate_of_directory_firm_id' => $match->id]);
        }

        return $match;
    }

    /**
     * @param  array<string, mixed>  $data  Same shape as a
     *                                      DirectoryImportRow's mapped_data: name_normalized/phone/website.
     */
    public function matchForMappedData(array $data, ?int $ignoreDirectoryFirmId = null): ?DirectoryFirm
    {
        $match = null;

        if (! empty($data['name_normalized'])) {
            $match = DirectoryFirm::query()
                ->where('name_normalized', $data['name_normalized'])
                ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
                ->first();
        }

        if ($match === null && ! empty($data['phone'])) {
            $match = DirectoryFirm::query()
                ->where('phone', $data['phone'])
                ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
                ->first();
        }

        // Disclosed limitation: no normalized website-domain column
        // exists to index against, so this signal loads every website-
        // having row and compares in PHP — acceptable for V1's
        // Michigan-scoped catalog size (a SuperAdmin-run, occasional
        // batch import / manual entry, not a hot path), not a design
        // meant to scale to a large multi-state catalog unchanged.
        if ($match === null && ! empty($data['website'])) {
            $domain = $this->domainOf($data['website']);
            if ($domain !== null) {
                $match = DirectoryFirm::query()
                    ->whereNotNull('website')
                    ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
                    ->get()
                    ->first(fn (DirectoryFirm $firm) => $this->domainOf($firm->website) === $domain);
            }
        }

        return $match;
    }

    private function domainOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: parse_url('https://'.$url, PHP_URL_HOST);

        return $host !== null ? strtolower((string) preg_replace('/^www\./', '', $host)) : null;
    }
}
