<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\LeadSourceResource\Pages;

use App\Filament\Firm\Resources\LeadSourceResource;
use App\Services\LeadSourceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CreateLeadSource — the ONLY UI path that may create a LeadSource row;
 * calls LeadSourceService::create() directly, NEVER a bare
 * `LeadSource::create()`. A duplicate-code rejection is translated into
 * a normal Filament field-level validation error, never an uncaught
 * 500.
 */
class CreateLeadSource extends CreateRecord
{
    protected static string $resource = LeadSourceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        try {
            return app(LeadSourceService::class)->create($firmUser->firm, $data['code'], $data['name']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.code' => $e->getMessage()]);
        }
    }
}
