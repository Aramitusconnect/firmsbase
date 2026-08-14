<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryAttorneyResource\Pages;

use App\Filament\Resources\DirectoryAttorneyResource;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Services\DirectoryAttorneyAdministrationService;
use App\Models\PlatformAdmin;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateDirectoryAttorney extends CreateRecord
{
    protected static string $resource = DirectoryAttorneyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $admin = Auth::guard('platform_admin')->user();
        abort_unless($admin instanceof PlatformAdmin, 403);

        $practiceAreaIds = array_map('intval', $data['practice_area_ids'] ?? []);
        $languageIds = array_map('intval', $data['language_ids'] ?? []);

        return app(DirectoryAttorneyAdministrationService::class)->create($data, $practiceAreaIds, $languageIds, $admin);
    }

    protected function getRedirectUrl(): string
    {
        /** @var DirectoryAttorney $record */
        $record = $this->getRecord();

        return DirectoryAttorneyResource::getUrl('view', ['record' => $record]);
    }
}
