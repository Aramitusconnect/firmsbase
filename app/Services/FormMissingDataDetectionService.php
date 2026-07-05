<?php

namespace App\Services;

use App\Enums\FormDraftValueSource;
use App\Models\FormDraft;
use App\Models\FormMissingDataItem;
use App\ValueObjects\MissingDataDetectionResult;

/**
 * FormMissingDataDetectionService — scans a draft's values against
 * its version's required fields. resolved_at is set automatically on
 * a re-scan that finds a previously-missing field now populated — no
 * separate human "resolve" action is needed.
 */
class FormMissingDataDetectionService
{
    public function scan(FormDraft $draft): MissingDataDetectionResult
    {
        $requiredFields = $draft->formTemplateVersion->fields()->where('is_required', true)->get();
        $values = $draft->values()->get()->keyBy('form_field_id');

        $missingFieldCodes = [];

        foreach ($requiredFields as $field) {
            $value = $values->get($field->id);
            $isMissing = ! $value || $value->source === FormDraftValueSource::Missing || $value->value === null || $value->value === '';

            $existingItem = FormMissingDataItem::query()
                ->where('form_draft_id', $draft->id)
                ->where('form_field_id', $field->id)
                ->whereNull('resolved_at')
                ->first();

            if ($isMissing) {
                $missingFieldCodes[] = $field->field_code;

                if (! $existingItem) {
                    FormMissingDataItem::create([
                        'form_draft_id' => $draft->id,
                        'form_field_id' => $field->id,
                        'detected_at' => now(),
                    ]);
                }
            } elseif ($existingItem) {
                $existingItem->update(['resolved_at' => now()]);
            }
        }

        return new MissingDataDetectionResult($draft->id, $missingFieldCodes);
    }
}
