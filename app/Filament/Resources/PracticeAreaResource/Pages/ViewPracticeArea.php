<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeAreaResource\Pages;

use App\Filament\Actions\Platform\ActivatePracticeAreaAction;
use App\Filament\Actions\Platform\DeactivatePracticeAreaAction;
use App\Filament\Actions\Platform\EditPracticeAreaAction;
use App\Filament\Resources\PracticeAreaResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewPracticeArea — Edit/Activate/Deactivate live here as header
 * actions, mirroring ViewPlan's convention. The Matter Types relation
 * manager (registered on the parent Resource) renders below the
 * infolist as its own tab — "Practice Area → Matter Types".
 */
class ViewPracticeArea extends ViewRecord
{
    protected static string $resource = PracticeAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditPracticeAreaAction::make(),
            ActivatePracticeAreaAction::make(),
            DeactivatePracticeAreaAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Practice Area')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('code'),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                ]),
        ]);
    }
}
