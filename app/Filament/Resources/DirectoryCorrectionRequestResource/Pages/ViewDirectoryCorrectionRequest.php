<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryCorrectionRequestResource\Pages;

use App\Filament\Actions\Platform\ApproveCorrectionRequestAction;
use App\Filament\Actions\Platform\RejectCorrectionRequestAction;
use App\Filament\Actions\Platform\ResolveCorrectionRequestAction;
use App\Filament\Resources\DirectoryCorrectionRequestResource;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ViewDirectoryCorrectionRequest extends ViewRecord
{
    protected static string $resource = DirectoryCorrectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApproveCorrectionRequestAction::make(),
            RejectCorrectionRequestAction::make(),
            ResolveCorrectionRequestAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report')
                ->columns(2)
                ->schema([
                    TextEntry::make('directoryFirm.display_name')->label('Listing'),
                    TextEntry::make('correction_type')->label('Type')->formatStateUsing(fn (CorrectionType $state) => $state->label())->badge(),
                    TextEntry::make('state')->badge()->formatStateUsing(fn (CorrectionState $state) => Str::headline($state->value)),
                    TextEntry::make('description')->columnSpanFull(),
                    TextEntry::make('reporter_name')->label('Reporter Name')->placeholder('Anonymous'),
                    TextEntry::make('reporter_email')->label('Reporter Email')->placeholder('—'),
                    TextEntry::make('reviewer_notes')->label('Reviewer Notes'),
                    TextEntry::make('rejection_reason')->label('Rejection Reason'),
                    TextEntry::make('resolution_notes')->label('Resolution Notes'),
                    TextEntry::make('decided_at')->dateTime(),
                ]),
        ]);
    }
}
