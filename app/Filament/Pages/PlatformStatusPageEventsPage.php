<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\StatusPageEventStatus;
use App\Filament\Actions\Platform\PublishStatusPageEventAction;
use App\Filament\Actions\Platform\ResolveStatusPageEventPubliclyAction;
use App\Filament\Actions\Platform\UnpublishStatusPageEventAction;
use App\Filament\Actions\Platform\UpdateStatusPageEventAction;
use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformStatusPageEventsPage — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Companion to Incidents:
 * `status_page_events` carries NO firm_id and NO RLS at all (platform-
 * level, Audit classification — see that model's own docblock), so
 * this page uses an ordinary Eloquent ->query() with no context
 * handling of any kind. "Current state" for a correlation_id is the
 * latest row, exactly like Incidents — selected via the same
 * MAX(id)-per-correlation_id subquery shape.
 *
 * No public status-page UI exists anywhere in this codebase (explicit
 * project rule — see StatusPageEvent's own docblock: "this is the
 * process/data foundation only") — this page is the platform-admin-
 * facing draft/publish/update/resolve/unpublish workflow, never a
 * public-facing page.
 */
class PlatformStatusPageEventsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Status Page';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 85;

    protected static ?string $title = 'Status Page';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            PublishStatusPageEventAction::make(),
        ];
    }

    public static function currentStateQuery(): Builder
    {
        return StatusPageEvent::query()
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')
                    ->from('status_page_events')
                    ->groupBy('correlation_id');
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::currentStateQuery())
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(StatusPageEventStatus::cases())
                        ->mapWithKeys(fn (StatusPageEventStatus $s): array => [$s->value => Str::headline($s->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('correlation_id')->label('Update')->limit(12)->fontFamily('mono'),
                TextColumn::make('component_affected')->label('Component')->sortable(),
                TextColumn::make('event_type')->label('Event type')->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (StatusPageEventStatus $state): string => Str::headline($state->value))
                    ->color(fn (StatusPageEventStatus $state): string => match ($state) {
                        StatusPageEventStatus::Published => 'success',
                        StatusPageEventStatus::Draft => 'warning',
                        StatusPageEventStatus::Unpublished, StatusPageEventStatus::Archived => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('public_message')->label('Public message')->limit(60),
                TextColumn::make('incident_correlation_id')->label('Linked incident')->limit(12)->fontFamily('mono')->placeholder('—'),
                TextColumn::make('starts_at')->label('Starts at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->label('Resolved at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                UpdateStatusPageEventAction::make(),
                ResolveStatusPageEventPubliclyAction::make(),
                UnpublishStatusPageEventAction::make(),
            ])
            ->emptyStateHeading('No status page updates recorded yet')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
