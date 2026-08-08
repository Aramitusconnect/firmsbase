<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\TimeEntryResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditTimeEntry — plain field edit via WrapsRecordMutationInFirmContext's
 * default handleRecordUpdate() (raw `TimeEntry::update()`), same
 * discipline as EditTask/EditContact. Reachable only for a Draft entry
 * (TimeEntryPolicy::update()'s extra status gate — TimeEntry has no
 * dedicated "edit" service method, and once submitted these fields feed
 * TimeEntryApprovalService::approve()'s billing-rate snapshot, so
 * editing outside Draft would silently desynchronize it). Status itself
 * is never an editable field — see TimeEntryResource's own docblock.
 *
 * Reuses the full TimeEntryResource::form() schema (matter_id/client_id/
 * hours/minutes/worked_on/is_billable/description) since none of those
 * fields carry any invariant a service layer needs to protect while the
 * entry remains Draft. `mutateFormDataBeforeFill()`/
 * `mutateFormDataBeforeSave()` convert between the stored whole-second
 * `seconds` column and the form's Hours/Minutes inputs.
 */
class EditTimeEntry extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = TimeEntryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $seconds = (int) ($data['seconds'] ?? 0);

        $data['hours'] = intdiv($seconds, 3600);
        $data['minutes'] = intdiv($seconds % 3600, 60);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['seconds'] = ((int) ($data['hours'] ?? 0)) * 3600 + ((int) ($data['minutes'] ?? 0)) * 60;

        unset($data['hours'], $data['minutes']);

        return $data;
    }
}
