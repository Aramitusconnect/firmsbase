<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\DocumentResource\Pages;

use App\Filament\ClientPortal\Resources\DocumentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListDocuments (Client Portal) — Follow-up 1 (Client Portal
 * Documents). No header actions — a client cannot upload/create a
 * document through this resource. Row scoping is
 * DocumentResource::getEloquentQuery()'s job (a UX-layer filter only,
 * never the real boundary — see that class's own docblock).
 */
class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
