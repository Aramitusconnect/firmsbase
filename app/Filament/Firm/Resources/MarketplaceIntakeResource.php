<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\MarketplaceIntakeStatus;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Pages\ListMarketplaceIntakes;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Pages\ViewMarketplaceIntake;
use App\Marketplace\Models\MarketplaceIntake;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * MarketplaceIntakeResource — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 9. The first Firm-authenticated UI this mission
 * has built (checkpoints 1-8 were public-route/service-layer only) —
 * a read-only queue plus a detail page. No create/edit pages: a
 * MarketplaceIntake is always visitor-created via the public intake
 * flow, never hand-authored by Firm staff (mirrors FirmLeadResource's
 * own "status is never a hand-set form field" discipline, taken one
 * step further — this Resource has no form() at all).
 *
 * Authorization: App\Policies\MarketplaceIntakePolicy, explicitly
 * registered via Gate::policy() in AppServiceProvider (this model
 * lives in App\Marketplace\Models, so Laravel's default policy
 * auto-discovery never finds it on its own — mirrors FirmIntegration's
 * own explicit registration).
 */
class MarketplaceIntakeResource extends Resource
{
    protected static ?string $model = MarketplaceIntake::class;

    protected static ?string $slug = 'myattorney-intakes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'MyAttorney Intakes';

    protected static string|\UnitEnum|null $navigationGroup = 'Clients & Matters';

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'prospect_name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prospect_name')->label('Prospect')->searchable()->placeholder('—'),
                TextColumn::make('prospect_email')->label('Email')->searchable()->placeholder('—'),
                TextColumn::make('prospect_phone')->label('Phone')->searchable()->placeholder('—'),
                TextColumn::make('practiceArea.name')->label('Practice Area')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? str($state->value)->replace('_', ' ')->headline()->toString() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'started', 'in_progress' => 'gray',
                        'submitted' => 'info',
                        'under_review' => 'warning',
                        'conflict_review_required' => 'danger',
                        'accepted', 'converted' => 'success',
                        'declined', 'abandoned', 'expired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(MarketplaceIntakeStatus::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => str($case->value)->replace('_', ' ')->headline()->toString()])
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceIntakes::route('/'),
            'view' => ViewMarketplaceIntake::route('/{record}'),
        ];
    }
}
