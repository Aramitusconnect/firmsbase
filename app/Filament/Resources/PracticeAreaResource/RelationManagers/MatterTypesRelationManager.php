<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeAreaResource\RelationManagers;

use App\Filament\Actions\Platform\ActivateMatterTypeAction;
use App\Filament\Actions\Platform\CreateMatterTypeAction;
use App\Filament\Actions\Platform\DeactivateMatterTypeAction;
use App\Filament\Actions\Platform\EditMatterTypeAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * MatterTypesRelationManager — "Practice Area → Matter Types", this
 * mission's required navigation shape. Every Matter Type is always
 * viewed/managed nested under its parent PracticeArea (the `matterTypes`
 * relation already defined on that model) — never as an independent
 * top-level resource. Mirrors PlatformInvoiceResource\RelationManagers\
 * InvoiceLinesRelationManager's shape (the only existing nested-
 * relation-manager precedent in the Admin panel).
 */
class MatterTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'matterTypes';

    protected static ?string $title = 'Matter Types';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->headerActions([
                CreateMatterTypeAction::make(),
            ])
            ->recordActions([
                EditMatterTypeAction::make(),
                ActivateMatterTypeAction::make(),
                DeactivateMatterTypeAction::make(),
            ])
            ->emptyStateHeading('No matter types under this practice area yet');
    }
}
