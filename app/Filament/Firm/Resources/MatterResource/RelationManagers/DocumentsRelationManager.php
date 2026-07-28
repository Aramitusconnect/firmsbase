<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Services\MatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * DocumentsRelationManager — Checkpoint 4 ("Plaid financial evidence
 * add-on"), "Documents" tab. Unlike FirmIntegrationResource's
 * RelationManagers, `Matter::documents()` is a real, already-defined
 * `HasMany` (`Matter.php`), so Filament's default `getRelationship()`
 * (driven by `$relationship` below) is sufficient — no manual `HasMany`
 * construction needed.
 *
 * Read-only in this checkpoint — no upload action here; document
 * upload has no HTTP/UI entry point anywhere in this codebase yet (per
 * the pre-construction inventory's §7 finding) and wiring one is out of
 * this checkpoint's scope (it is the Client Portal's own future upload
 * feature, not the Firm-side Matter page's).
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')->label('File')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                TextColumn::make('scan_status')
                    ->label('Scan')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'clean' => 'success',
                        'pending' => 'gray',
                        'infected', 'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('uploadedBy.name')->label('Uploaded by')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
