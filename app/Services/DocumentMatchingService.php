<?php

namespace App\Services;

use App\Enums\DocumentRequestItemStatus;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Support\Collection;

/**
 * DocumentMatchingService — Zero-Click Core Workflow Automation, item
 * 5. Deterministic, conservative candidate-finding ONLY — no filename
 * heuristics, no document-type/category taxonomy (confirmed absent
 * repository-wide by this mission's own audit), no AI/LLM
 * classification of any kind. The only signal used is structural:
 * which DocumentRequestItems are still open (chase-eligible status)
 * for the SAME Firm + Matter the Document was uploaded against.
 *
 * "Exactly one open item" is the only case ever treated as a safe
 * automatic match — this mirrors item 5's own literal example ("Same
 * Firm, Same Client, Same Matter, Open request → safe candidate").
 * Two or more candidates is always ambiguous and must never be
 * auto-resolved (item 5: "If more than one reasonable match exists:
 * do not auto-complete — create Review Document Classification/
 * Matching task").
 */
class DocumentMatchingService
{
    /**
     * @return Collection<int, DocumentRequestItem>
     */
    public function candidatesFor(Firm $firm, Matter $matter): Collection
    {
        return DocumentRequestItem::query()
            ->whereIn('status', [
                DocumentRequestItemStatus::Requested->value,
                DocumentRequestItemStatus::Viewed->value,
                DocumentRequestItemStatus::NeedsReplacement->value,
            ])
            ->whereHas('documentRequest', fn ($q) => $q->where('firm_id', $firm->id)->where('matter_id', $matter->id))
            ->get();
    }

    /**
     * @return DocumentRequestItem|null the single safe match, or null
     *                                  when zero or more than one
     *                                  candidate exists (never a
     *                                  guess)
     */
    public function singleSafeMatch(Firm $firm, Matter $matter): ?DocumentRequestItem
    {
        $candidates = $this->candidatesFor($firm, $matter);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
