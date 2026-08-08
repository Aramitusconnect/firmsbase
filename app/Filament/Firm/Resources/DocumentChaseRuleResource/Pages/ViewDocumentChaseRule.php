<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages;

use App\Filament\Firm\Resources\DocumentChaseRuleResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewDocumentChaseRule — read-only Infolist for the rule's own
 * configuration; the append-only DocumentChaseEvent history for this
 * rule is shown below via ChaseEventsRelationManager (see
 * DocumentChaseRuleResource::getRelations()).
 */
class ViewDocumentChaseRule extends ViewRecord
{
    protected static string $resource = DocumentChaseRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'Chase rules define reminder eligibility only. Automatic reminder sending is not yet enabled — no email, SMS, or other message is actually sent to any client.';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Chase Rule')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('applies_to')->label('Applies To')->placeholder('Firm-wide'),
                    TextEntry::make('channel')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('reminder_offsets_days')
                        ->label('Reminder Offsets (days)')
                        ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state),
                    TextEntry::make('max_reminders')->label('Max Reminders'),
                    TextEntry::make('escalate_after_days')->label('Escalate After (days)')->placeholder('—'),
                    TextEntry::make('escalateToUser.name')->label('Escalate To')->placeholder('—'),
                    TextEntry::make('createdBy.name')->label('Created By')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
