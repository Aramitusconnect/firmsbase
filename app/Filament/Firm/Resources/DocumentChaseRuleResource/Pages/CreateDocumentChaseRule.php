<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\DocumentChaseRuleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * CreateDocumentChaseRule — direct Eloquent create via
 * WrapsRecordMutationInFirmContext (see DocumentChaseRuleResource's own
 * docblock for why this is the correct write path: no dedicated
 * service exists for this model). `created_by` is derived server-side
 * from the acting FirmUser here, never a form field a user could type
 * an arbitrary value into. `reminder_offsets_days` is submitted by
 * TagsInput as an array of strings; normalized to ints so the stored
 * shape always matches what DocumentChaseSchedulerService::
 * isReminderDue() expects (`in_array($daysSinceRequested, $offsets,
 * true)` — a strict comparison that would silently never match a
 * string "7" against an int 7).
 */
class CreateDocumentChaseRule extends CreateRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = DocumentChaseRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $firmUser = Auth::user()?->activeFirmUser();

        $data['created_by'] = $firmUser?->user_id;

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
