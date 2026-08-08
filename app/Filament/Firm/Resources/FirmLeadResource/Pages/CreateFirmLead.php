<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\FirmLeadResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * CreateFirmLead — "+ Add Lead", a plain, unrestricted create (Firm
 * Feature Manifest §1: "Lead creation unrestricted"). `status` is
 * never set here — FirmLeadResource::form() has no status field at
 * all, so every new lead is created with the model's own default
 * (FirmLeadStatus::New per FirmLeadFactory/the migration default),
 * never anything hand-picked on this form.
 */
class CreateFirmLead extends CreateRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = FirmLeadResource::class;
}
