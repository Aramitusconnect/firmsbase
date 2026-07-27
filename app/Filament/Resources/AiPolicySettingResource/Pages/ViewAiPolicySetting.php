<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPolicySettingResource\Pages;

use App\Filament\Actions\Platform\EditAiPolicySettingValueAction;
use App\Filament\Resources\AiPolicySettingResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewAiPolicySetting — a standard Eloquent-backed ViewRecord (see
 * AiPolicySettingResource's own docblock for why this table needs no
 * cross-firm-read workaround, unlike this phase's other resources).
 * value_json is rendered as formatted, escaped JSON text via a plain
 * TextEntry — no raw-HTML-rendering method is invoked, never
 * unescaped output.
 */
class ViewAiPolicySetting extends ViewRecord
{
    protected static string $resource = AiPolicySettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAiPolicySettingValueAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('AI Policy Setting')
                ->columns(1)
                ->schema([
                    TextEntry::make('key'),
                    TextEntry::make('value_json')
                        ->label('Value')
                        ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                    TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                ]),
        ]);
    }
}
