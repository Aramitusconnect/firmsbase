<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\ClientResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditClient — see ClientResource's own docblock for the documented
 * decision on why a direct Eloquent update (via
 * WrapsRecordMutationInFirmContext) is acceptable here: only genuinely
 * safe profile fields are on this form (display_name, legal_name,
 * email, phone, preferred_language, preferred_timezone — see
 * ClientResource::form()); portal_status/portal_invitation_* (wildcard)/
 * communication_preferences_id/created_by are never editable fields on
 * this page, so this can never re-trigger or fake any part of the
 * conversion/portal-invitation lifecycle those columns belong to.
 */
class EditClient extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = ClientResource::class;
}
