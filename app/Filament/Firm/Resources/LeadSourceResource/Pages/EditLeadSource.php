<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\LeadSourceResource\Pages;

use App\Filament\Firm\Resources\LeadSourceResource;
use App\Models\LeadSource;
use App\Services\LeadSourceService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * EditLeadSource — deliberately overrides `handleRecordUpdate()` to
 * call `LeadSourceService::update()` directly, NEVER a bare
 * `$leadSource->update()`. A duplicate-code rejection is translated
 * into a normal Filament field-level validation error, never an
 * uncaught 500.
 */
class EditLeadSource extends EditRecord
{
    protected static string $resource = LeadSourceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        /** @var LeadSource $record */
        try {
            return app(LeadSourceService::class)->update($firmUser->firm, $record, $data['code'], $data['name']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.code' => $e->getMessage()]);
        }
    }
}
