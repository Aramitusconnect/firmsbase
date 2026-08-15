<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use Illuminate\Support\Str;

/**
 * MarketplaceImportDuplicateDetectionService — Mission 2 (MyAttorney
 * Marketplace Core), section 52. Multi-signal matching against every
 * EXISTING directory_firms row — normalized name, normalized phone,
 * and website domain, exactly the signal set section 52 names (office/
 * attorney-relationship signals are checkpoint 9's disclosed, deferred
 * scope — this checkpoint covers Firm-level CSV import only; office
 * bulk import is a future addition, not a re-architecture). A match
 * NEVER auto-merges — the row is marked Duplicate with the candidate
 * recorded, and stays that way until an admin explicitly decides via
 * MarketplaceImportApplyService.
 *
 * matchForMappedData() was extracted (SuperAdmin console
 * professionalization mission, MYAT2) so DirectoryFirmResource's manual
 * "Add Firm" form can reuse the exact same matching logic as a live
 * duplicate warning, instead of re-implementing it against a
 * DirectoryImportRow the manual-entry path doesn't have. detectRow()'s
 * own behavior (including its side effect of updating the row) is
 * unchanged — it now just delegates the matching itself to the new
 * method.
 *
 * MyAttorney final hardening mission, findings 6/7/12: name/phone
 * matching now falls back to a stronger, deliberately conservative
 * normalization (punctuation/whitespace, never a legal-suffix word
 * map — "Smith Law" and "Smith Legal Group" must never collide) when
 * the fast exact-column match misses, and findDuplicateCandidate()
 * returns WHY a candidate matched (used by the manual Add Firm/Add
 * Attorney "Create Anyway" governance flow and its audit event — never
 * a fabricated "N% probability", only the actual deterministic signals
 * that fired). findAttorneyDuplicateCandidate() is a genuinely NEW
 * capability (DirectoryAttorneyResource's manual "Add Attorney" form
 * had no duplicate check at all before this mission) reusing the same
 * name-normalization helper, matched instead on name + bar_number (the
 * two real identity signals DirectoryAttorney actually has — it has no
 * phone/website column to reuse the Firm-side signals for).
 */
class MarketplaceImportDuplicateDetectionService
{
    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $audit = new PlatformAdminAuditEventRecorder,
    ) {}

    /**
     * $actor is optional — see MarketplaceImportValidationService::
     * validateBatch()'s own docblock for why. When supplied, records a
     * `marketplace_import_duplicates_evaluated` audit event (MyAttorney
     * final hardening mission, finding 4).
     */
    public function detectBatch(DirectoryImportBatch $batch, ?PlatformAdmin $actor = null): DirectoryImportBatch
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

        $fresh = $batch->fresh();

        if ($actor !== null) {
            $this->audit->recordPlatformEvent($actor, 'marketplace_import_duplicates_evaluated', 'marketplace_import', [
                'directory_import_batch_id' => $fresh->id,
                'directory_import_batch_uuid' => (string) $fresh->uuid,
                'duplicate_rows' => $duplicateCount,
            ]);
        }

        return $fresh;
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
        return $this->findDuplicateCandidate($data, $ignoreDirectoryFirmId)['firm'] ?? null;
    }

    /**
     * The reason-tracking core this class' matching now runs through.
     * Checks all three signals (never short-circuits on the first hit)
     * so a candidate matched on more than one signal is reported with
     * every reason it fired for — real evidence for a human reviewer,
     * never a fabricated confidence score. When more than one distinct
     * firm matches across the three signals, the firm matched by the
     * MOST signals wins (strongest evidence); ties keep this method's
     * own name > phone > website precedence.
     *
     * @param  array<string, mixed>  $data
     * @return array{firm: DirectoryFirm, reasons: array<int, string>}|null
     */
    public function findDuplicateCandidate(array $data, ?int $ignoreDirectoryFirmId = null): ?array
    {
        /** @var array<int, array{firm: DirectoryFirm, reasons: array<int, string>}> $byFirmId */
        $byFirmId = [];

        $addMatch = function (?DirectoryFirm $firm, string $reason) use (&$byFirmId): void {
            if ($firm === null) {
                return;
            }

            $byFirmId[$firm->id] ??= ['firm' => $firm, 'reasons' => []];
            $byFirmId[$firm->id]['reasons'][] = $reason;
        };

        if (! empty($data['name_normalized'])) {
            $addMatch($this->matchFirmByName((string) $data['name_normalized'], $ignoreDirectoryFirmId), 'Same normalized legal name');
        }

        if (! empty($data['phone'])) {
            $addMatch($this->matchFirmByPhone((string) $data['phone'], $ignoreDirectoryFirmId), 'Same normalized phone number');
        }

        if (! empty($data['website'])) {
            $addMatch($this->matchFirmByWebsiteDomain((string) $data['website'], $ignoreDirectoryFirmId), 'Same website domain');
        }

        if ($byFirmId === []) {
            return null;
        }

        $best = collect($byFirmId)->sortByDesc(fn (array $candidate): int => count($candidate['reasons']))->first();

        if (count($best['reasons']) > 1) {
            $best['reasons'][] = 'Multiple matching attributes';
        }

        return $best;
    }

    private function matchFirmByName(string $nameNormalized, ?int $ignoreDirectoryFirmId): ?DirectoryFirm
    {
        $exact = DirectoryFirm::query()
            ->where('name_normalized', $nameNormalized)
            ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        // Disclosed limitation: matches this class' own established
        // website-domain-check pattern below — loads every row and
        // compares in PHP. Acceptable for V1's Michigan-scoped catalog
        // size (a SuperAdmin-run, occasional batch import / manual
        // entry, not a hot path), not a design meant to scale to a
        // large multi-state catalog unchanged.
        $target = $this->normalizeNameForMatching($nameNormalized);
        if ($target === '') {
            return null;
        }

        return DirectoryFirm::query()
            ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
            ->get()
            ->first(fn (DirectoryFirm $firm) => $this->normalizeNameForMatching((string) $firm->name_normalized) === $target);
    }

    private function matchFirmByPhone(string $phone, ?int $ignoreDirectoryFirmId): ?DirectoryFirm
    {
        $exact = DirectoryFirm::query()
            ->where('phone', $phone)
            ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        $target = $this->normalizePhoneForMatching($phone);
        if ($target === null) {
            return null;
        }

        return DirectoryFirm::query()
            ->whereNotNull('phone')
            ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
            ->get()
            ->first(fn (DirectoryFirm $firm) => $this->normalizePhoneForMatching((string) $firm->phone) === $target);
    }

    private function matchFirmByWebsiteDomain(string $website, ?int $ignoreDirectoryFirmId): ?DirectoryFirm
    {
        $domain = $this->domainOf($website);

        if ($domain === null) {
            return null;
        }

        // Disclosed limitation: no normalized website-domain column
        // exists to index against, so this signal loads every website-
        // having row and compares in PHP — acceptable for V1's
        // Michigan-scoped catalog size (a SuperAdmin-run, occasional
        // batch import / manual entry, not a hot path), not a design
        // meant to scale to a large multi-state catalog unchanged.
        return DirectoryFirm::query()
            ->whereNotNull('website')
            ->when($ignoreDirectoryFirmId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryFirmId))
            ->get()
            ->first(fn (DirectoryFirm $firm) => $this->domainOf($firm->website) === $domain);
    }

    /**
     * MyAttorney final hardening mission, finding 7 (Add Attorney had
     * no duplicate check of any kind before this mission). Matches on
     * name (same conservative normalization as the Firm side) and
     * bar_number (an exact, trimmed comparison only — a bar number is
     * either the same identifier or it is not; there is no safe
     * "fuzzy" variant of it the way a phone number's formatting
     * varies).
     *
     * @param  array<string, mixed>  $data  name/bar_number, the same
     *                                      shape DirectoryAttorneyAdministrationService's own create()/update() accept.
     * @return array{attorney: DirectoryAttorney, reasons: array<int, string>}|null
     */
    public function findAttorneyDuplicateCandidate(array $data, ?int $ignoreDirectoryAttorneyId = null): ?array
    {
        /** @var array<int, array{attorney: DirectoryAttorney, reasons: array<int, string>}> $byAttorneyId */
        $byAttorneyId = [];

        $addMatch = function (?DirectoryAttorney $attorney, string $reason) use (&$byAttorneyId): void {
            if ($attorney === null) {
                return;
            }

            $byAttorneyId[$attorney->id] ??= ['attorney' => $attorney, 'reasons' => []];
            $byAttorneyId[$attorney->id]['reasons'][] = $reason;
        };

        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $target = $this->normalizeNameForMatching($name);
            $match = DirectoryAttorney::query()
                ->when($ignoreDirectoryAttorneyId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryAttorneyId))
                ->get()
                ->first(fn (DirectoryAttorney $attorney) => $this->normalizeNameForMatching((string) $attorney->name_normalized) === $target);
            $addMatch($match, 'Same normalized name');
        }

        $barNumber = trim((string) ($data['bar_number'] ?? ''));
        if ($barNumber !== '') {
            $match = DirectoryAttorney::query()
                ->whereNotNull('bar_number')
                ->where('bar_number', $barNumber)
                ->when($ignoreDirectoryAttorneyId !== null, fn ($query) => $query->whereKeyNot($ignoreDirectoryAttorneyId))
                ->first();
            $addMatch($match, 'Same bar number');
        }

        if ($byAttorneyId === []) {
            return null;
        }

        $best = collect($byAttorneyId)->sortByDesc(fn (array $candidate): int => count($candidate['reasons']))->first();

        if (count($best['reasons']) > 1) {
            $best['reasons'][] = 'Multiple matching attributes';
        }

        return $best;
    }

    /**
     * Deliberately conservative: case-folds, strips periods/commas
     * (so "P.L.L.C." and "PLLC" and "P,L,L,C" all reduce the same
     * way), and collapses whitespace. Does NOT map legal-suffix WORDS
     * to each other (e.g. never treats "LLC" and "Limited Liability
     * Company" as equivalent, never strips a suffix word outright) —
     * that class of normalization risks exactly the false-positive
     * this mission explicitly warns against ("Smith Law" and "Smith
     * Legal Group" must never collide).
     */
    private function normalizeNameForMatching(string $name): string
    {
        $name = Str::lower($name);
        $name = str_replace([',', '.'], '', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * Strips everything except digits (parentheses, hyphens, spaces,
     * a leading "+") so "(313) 555-1212", "313-555-1212", and
     * "3135551212" all compare equal. No international-dialing
     * assumptions beyond digit-only comparison — this deliberately
     * does NOT attempt to normalize a "+1" country code away from a
     * bare 10-digit number, since that would risk conflating two
     * genuinely different numbers that merely share the same last 10
     * digits. Returns null (never matches) for anything shorter than
     * 7 digits after stripping — too short to be a real phone number,
     * not worth risking a false-positive collision on stray digits.
     */
    private function normalizePhoneForMatching(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 7 ? $digits : null;
    }

    private function domainOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: parse_url('https://'.$url, PHP_URL_HOST);

        return $host !== null ? strtolower((string) preg_replace('/^www\./', '', $host)) : null;
    }
}
