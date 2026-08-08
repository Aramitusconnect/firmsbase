<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ContactResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\ContactResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditContact — deliberately no delete action: ContactPolicy declares
 * no delete() ability (deletion is out of this module's scope — the
 * Firm Feature Manifest never calls for a "delete contact" capability,
 * and this codebase's convention elsewhere is retention/status-flip,
 * never a hard delete, for tenant-owned records with any downstream
 * reference).
 */
class EditContact extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = ContactResource::class;
}
