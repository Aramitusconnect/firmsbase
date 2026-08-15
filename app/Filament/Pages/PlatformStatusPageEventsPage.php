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
use App\Services\StatusPagePublicationCapabilityService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
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
 *
 * OPERATIONS CONTROL PLANE CORRECTION. That fact was previously
 * recorded only in this docblock, while the page itself showed a
 * green "Published" badge, a "Publish" header action and a "Resolve
 * Publicly" row action. An operator mid-incident reads the badge, not
 * the source code, and would reasonably conclude customers had been
 * told. Nobody had been told. The publication semantics are now
 * disclosed on the page itself, derived at render time from
 * StatusPagePublicationCapabilityService (which inspects the real
 * route table rather than trusting a constant), and the state column
 * is labelled with what the record actually is.
 */
class PlatformStatusPageEventsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Status Page';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 86;

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
            $this->publicationSemanticsSection(),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * The first thing an operator sees on this page: exactly what
     * "Publish" does and does not do. Deliberately not collapsible —
     * a collapsed warning about customer communication is a warning
     * nobody reads during an incident.
     */
    private function publicationSemanticsSection(): Section
    {
        $capability = app(StatusPagePublicationCapabilityService::class);
        $isPublic = $capability->hasPublicPublicationBackend();

        return Section::make($isPublic ? 'Public Status Publication' : 'These Updates Are NOT Published Publicly')
            ->icon($isPublic ? Heroicon::OutlinedGlobeAlt : Heroicon::OutlinedExclamationTriangle)
            ->schema([
                Text::make($capability->disclosure())->color($isPublic ? 'gray' : 'danger'),
                Text::make(
                    'Internal notes are never part of a public message. Anything typed into a public message field '.
                    'should be written as if it were already external: no hostnames, IPs, AWS identifiers, database '.
                    'names, stack traces, security-investigation detail, or customer names.'
                )->color('gray'),
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
                    ->label('Record state')
                    ->badge()
                    ->formatStateUsing(fn (StatusPageEventStatus $state): string => $this->recordStateLabel($state))
                    // Published is deliberately NOT green while no
                    // public endpoint exists: a green "Published"
                    // badge is read as "customers have been told,"
                    // which would be false.
                    ->color(fn (StatusPageEventStatus $state): string => match ($state) {
                        StatusPageEventStatus::Published => app(StatusPagePublicationCapabilityService::class)->hasPublicPublicationBackend() ? 'success' : 'warning',
                        StatusPageEventStatus::Draft => 'gray',
                        StatusPageEventStatus::Unpublished, StatusPageEventStatus::Archived => 'gray',
                    })
                    ->tooltip(fn (): string => app(StatusPagePublicationCapabilityService::class)->publicationSemanticsLabel())
                    ->sortable(),
                TextColumn::make('public_message')->label('Public message')->limit(60),
                TextColumn::make('incident_correlation_id')->label('Linked incident')->limit(12)->fontFamily('mono')->placeholder('Not linked'),
                TextColumn::make('starts_at')->label('Starts at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->label('Resolved at')->dateTime()->placeholder('Not resolved')->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                UpdateStatusPageEventAction::make(),
                ResolveStatusPageEventPubliclyAction::make(),
                UnpublishStatusPageEventAction::make(),
            ])
            ->emptyStateHeading('No status page updates recorded yet')
            ->emptyStateDescription(
                app(StatusPagePublicationCapabilityService::class)->hasPublicPublicationBackend()
                    ? 'No public status updates have been published.'
                    : 'No internal status records exist. Note that no public status page exists to publish to.'
            )
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * The record-state label. While no public endpoint exists,
     * "Published" is rendered as "Recorded (Not Public)" so the word
     * on screen matches what actually happened.
     */
    private function recordStateLabel(StatusPageEventStatus $state): string
    {
        if ($state !== StatusPageEventStatus::Published) {
            return Str::headline($state->value);
        }

        return app(StatusPagePublicationCapabilityService::class)->hasPublicPublicationBackend()
            ? 'Published'
            : 'Recorded (Not Public)';
    }
}
