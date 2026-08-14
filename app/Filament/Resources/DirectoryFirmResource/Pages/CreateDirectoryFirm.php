<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryFirmResource\Pages;

use App\Filament\Resources\DirectoryFirmResource;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\DirectoryFirmAdministrationService;
use App\Models\PlatformAdmin;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * CreateDirectoryFirm — MyAttorney SuperAdmin console professionalization
 * mission (MYAT2). handleRecordCreation() is overridden (rather than
 * relying on Filament's default `static::getModel()::create($data)`)
 * because this write genuinely needs to fan out into three places
 * (DirectoryFirm, its primary FirmOffice, the practice-area/language
 * pivots) plus an audit event — all of which
 * DirectoryFirmAdministrationService::create() already does inside one
 * transaction. See that service's own docblock for why claimed/
 * verified/member status is never touched here.
 */
class CreateDirectoryFirm extends CreateRecord
{
    protected static string $resource = DirectoryFirmResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $admin = Auth::guard('platform_admin')->user();
        abort_unless($admin instanceof PlatformAdmin, 403);

        $practiceAreaIds = array_map('intval', $data['practice_area_ids'] ?? []);
        $languageIds = array_map('intval', $data['language_ids'] ?? []);

        return app(DirectoryFirmAdministrationService::class)->create($data, $practiceAreaIds, $languageIds, $admin);
    }

    protected function getRedirectUrl(): string
    {
        /** @var DirectoryFirm $record */
        $record = $this->getRecord();

        return DirectoryFirmResource::getUrl('view', ['record' => $record]);
    }
}
