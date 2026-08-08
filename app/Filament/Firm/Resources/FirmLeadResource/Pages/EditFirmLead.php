<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\FirmLeadResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditFirmLead — status is never an editable field here (see
 * FirmLeadResource::form(), which has no status field at all), so this
 * page can never set FirmLeadStatus::Converted or any other status
 * value, regardless of role. FirmLeadPolicy::update() additionally
 * denies editing entirely once a lead is Converted (mount() aborts
 * with 403 via Filament's own authorizeAccess(), which checks
 * static::getResource()::canEdit($record) — this resolves to
 * Gate::authorize('update', $record) against FirmLeadPolicy).
 */
class EditFirmLead extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = FirmLeadResource::class;
}
