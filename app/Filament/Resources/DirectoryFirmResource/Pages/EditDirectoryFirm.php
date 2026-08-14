<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryFirmResource\Pages;

use App\Filament\Resources\DirectoryFirmResource;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\DirectoryFirmAdministrationService;
use App\Models\PlatformAdmin;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * EditDirectoryFirm — MyAttorney SuperAdmin console professionalization
 * mission (MYAT2). Same reasoning as CreateDirectoryFirm's own docblock
 * for overriding handleRecordUpdate() instead of the default
 * $record->update($data). mutateFormDataBeforeFill() injects the
 * primary FirmOffice's address fields into the form's initial state —
 * they are not columns on DirectoryFirm itself, so Filament's default
 * fill (which reads straight off $record's own attributes) would leave
 * them blank otherwise.
 */
class EditDirectoryFirm extends EditRecord
{
    protected static string $resource = DirectoryFirmResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var DirectoryFirm $record */
        $record = $this->getRecord();
        $office = $record->offices()->where('is_primary', true)->first();

        if ($office !== null) {
            $data['address_line1'] = $office->address_line1;
            $data['address_line2'] = $office->address_line2;
            $data['city'] = $office->city;
            $data['state'] = $office->state;
            $data['postal_code'] = $office->postal_code;
            $data['country'] = $office->country;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $admin = Auth::guard('platform_admin')->user();
        abort_unless($admin instanceof PlatformAdmin, 403);

        /** @var DirectoryFirm $record */
        $practiceAreaIds = array_map('intval', $data['practice_area_ids'] ?? []);
        $languageIds = array_map('intval', $data['language_ids'] ?? []);

        return app(DirectoryFirmAdministrationService::class)->update($record, $data, $practiceAreaIds, $languageIds, $admin);
    }

    protected function getRedirectUrl(): string
    {
        /** @var DirectoryFirm $record */
        $record = $this->getRecord();

        return DirectoryFirmResource::getUrl('view', ['record' => $record]);
    }
}
