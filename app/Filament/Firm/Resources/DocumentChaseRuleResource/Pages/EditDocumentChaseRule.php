<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\DocumentChaseRuleResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditDocumentChaseRule — same direct-Eloquent-update reasoning as
 * CreateDocumentChaseRule (no dedicated service exists for this
 * model). Reuses the full DocumentChaseRuleResource::form() — unlike
 * DocumentRequestResource, there is no separate service invariant to
 * protect on any field here, so no narrower page-level form() override
 * is needed.
 */
class EditDocumentChaseRule extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = DocumentChaseRuleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['reminder_offsets_days']) && is_array($data['reminder_offsets_days'])) {
            $data['reminder_offsets_days'] = array_map('intval', $data['reminder_offsets_days']);
        }

        if (isset($data['max_reminders'])) {
            $data['max_reminders'] = (int) $data['max_reminders'];
        }

        if (isset($data['escalate_after_days']) && $data['escalate_after_days'] !== null && $data['escalate_after_days'] !== '') {
            $data['escalate_after_days'] = (int) $data['escalate_after_days'];
        }

        return $data;
    }
}
