<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\DocumentRequestResource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * EditDocumentRequest — deliberately overrides the page-level `form()`
 * with a NARROWER schema than DocumentRequestResource::form() (which
 * CreateDocumentRequest uses in full) — see DocumentRequestPolicy's own
 * docblock for the reasoning: `DocumentRequestService` has no update()
 * method for the parent request, and `client_id`/`matter_id`/`status`/
 * `items` are excluded here because changing them would either
 * desynchronize the request from the party/matter it already has items
 * addressed to, or bypass `DocumentRequestService::
 * recomputeParentStatus()`'s exclusive ownership of `status`. Only
 * title/instructions/due_at remain — none of these carry any invariant
 * a service layer would need to protect, same reasoning EditClient/
 * EditDeadline document for their own narrow allowlists. Direct
 * Eloquent update via WrapsRecordMutationInFirmContext is therefore
 * acceptable here.
 */
class EditDocumentRequest extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = DocumentRequestResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document Request')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    Textarea::make('instructions')->rows(3)->columnSpanFull(),
                    DateTimePicker::make('due_at')->label('Due At')->native(false),
                ]),
        ]);
    }
}
