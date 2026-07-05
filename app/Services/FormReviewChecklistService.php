<?php

namespace App\Services;

use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormReviewChecklistItem;

/**
 * FormReviewChecklistService — seed/check/uncheck
 * form_review_checklist_items. seedDefaults() gives every draft a
 * small, fixed set of review checklist items — the concrete data
 * backing the WCAG "accessible checklist controls" readiness item.
 */
class FormReviewChecklistService
{
    private const DEFAULT_ITEMS = [
        'names_match_intake' => 'Names on the form match the client/matter intake data.',
        'dates_verified' => 'Dates on the form have been verified against source records.',
        'signature_block_present' => 'A signature block is present where required.',
        'no_placeholder_text_remains' => 'No unresolved placeholder or sample text remains on the form.',
    ];

    public function seedDefaults(FormDraft $draft): void
    {
        foreach (self::DEFAULT_ITEMS as $code => $label) {
            FormReviewChecklistItem::query()->firstOrCreate(
                ['form_draft_id' => $draft->id, 'checklist_code' => $code],
                ['label' => $label, 'is_checked' => false]
            );
        }
    }

    public function check(FormReviewChecklistItem $item, FirmUser $actor): FormReviewChecklistItem
    {
        $item->update([
            'is_checked' => true,
            'checked_by_firm_user_id' => $actor->id,
            'checked_at' => now(),
        ]);

        return $item->fresh();
    }

    public function uncheck(FormReviewChecklistItem $item): FormReviewChecklistItem
    {
        $item->update([
            'is_checked' => false,
            'checked_by_firm_user_id' => null,
            'checked_at' => null,
        ]);

        return $item->fresh();
    }

    public function isComplete(FormDraft $draft): bool
    {
        return $draft->checklistItems()->where('is_checked', false)->doesntExist()
            && $draft->checklistItems()->count() > 0;
    }
}
