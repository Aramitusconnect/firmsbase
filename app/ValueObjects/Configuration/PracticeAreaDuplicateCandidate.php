<?php

declare(strict_types=1);

namespace App\ValueObjects\Configuration;

use App\Models\PracticeArea;

/**
 * PracticeAreaDuplicateCandidate — one existing PracticeArea that
 * normalizes onto the same identifier as the value being created/
 * edited, together with the EVIDENCE for why it collided.
 *
 * Deliberately carries `matchReasons` rather than a bare boolean:
 * mission section 28 requires the duplicate warning to show the
 * operator what actually matched (name vs code vs slug vs a stored
 * alias), and section 29 forbids treating a name-similarity hit as
 * automatic proof of equivalence. A candidate is therefore always
 * only ever SUSPECTED — this object never carries a CONFIRMED
 * verdict, because confirming two practice areas are the same thing
 * is a semantic judgement about legal taxonomy that no string
 * comparison can make (see PracticeAreaCanonicalizationService's own
 * docblock, and section 36's owner-approval gate on any real merge).
 */
final readonly class PracticeAreaDuplicateCandidate
{
    /**
     * @param  list<string>  $matchReasons  Human-readable, e.g. "Name normalizes to \"civil litigation\""
     */
    public function __construct(
        public PracticeArea $practiceArea,
        public array $matchReasons,
    ) {}

    public function summaryLine(): string
    {
        return sprintf(
            '#%d %s (code: %s, slug: %s, %s)',
            $this->practiceArea->id,
            $this->practiceArea->name,
            $this->practiceArea->code,
            $this->practiceArea->slug ?? 'none',
            $this->practiceArea->is_active ? 'Active' : 'Inactive',
        );
    }
}
