<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ContactResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\ContactResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContact extends CreateRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = ContactResource::class;
}
