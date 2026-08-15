<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SupportAccessSessionStatus;
use App\Filament\Actions\Platform\RevokeApprovedSupportSessionAction;
use App\Filament\Resources\SupportSessionResource\Pages\ListSupportSessions;
use App\Filament\Resources\SupportSessionResource\Pages\ViewSupportSession;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessSession;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PlatformSupportAccessDirectoryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * SupportSessionResource ("Approved Support Sessions") — Phase 4
 * (FirmsVault Platform Admin Control Center, "Support" category).
 * Global, cross-firm oversight of `support_access_sessions` rows — the
 * active/historical time-limited access grant facet of the same
 * underlying support-access workflow SupportCaseResource (Support
 * Cases) also covers. See PlatformSupportAccessDirectoryService's own
 * docblock for the full architectural rationale.
 *
 * The one mutating action (Revoke) is routed exclusively through
 * PlatformFirmIntegrationBoundedAccessService::revokeSupportAccessSession()
 * — the already-wired, already-TOCTOU-safe, dual-audited Checkpoint 11
 * chokepoint — via RevokeApprovedSupportSessionAction, never
 * reimplemented here.
 */
class SupportSessionResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = SupportAccessSession::class;

    protected static ?string $slug = 'approved-support-sessions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Approved Support Sessions';

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 61;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        // See SupportCaseResource's own docblock/canAccess() for why
        // this deliberately reuses canAccessIntegrationOversight()
        // rather than a new read gate.
        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];

                $rows = app(PlatformSupportAccessDirectoryService::class)->listApprovedSupportSessions($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                ]);

                return $rows->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(SupportAccessSessionStatus::cases())
                        ->mapWithKeys(fn (SupportAccessSessionStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('reference')->label('Session')->searchable()->copyable(),
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('platform_admin_name')->label('Platform admin')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    // A row persisted as Active whose expiry has passed is
                    // labelled "Active (expired)", never a plain green
                    // Active: it authorizes nothing, and showing it as live
                    // access would misrepresent the platform's actual reach
                    // into that firm. The distinction is carried in the
                    // label text, not only in the badge colour.
                    ->formatStateUsing(fn (?string $state, array $record): string => match (true) {
                        $state === null => '—',
                        $state === SupportAccessSessionStatus::Active->value && ! ($record['is_currently_valid'] ?? false) => 'Active (expired)',
                        default => Str::headline($state),
                    })
                    ->color(fn (?string $state, array $record): string => match (true) {
                        $state === SupportAccessSessionStatus::Active->value && ($record['is_currently_valid'] ?? false) => 'success',
                        $state === SupportAccessSessionStatus::Active->value => 'warning',
                        $state === SupportAccessSessionStatus::Revoked->value => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('time_remaining')
                    ->label('Time remaining')
                    ->placeholder('Not authorizing access')
                    ->tooltip('Derived from the server clock. The server re-checks expiry on every access, so this is what it would authorize against right now.'),
                TextColumn::make('started_at')->label('Started at')->dateTime(),
                TextColumn::make('expires_at')->label('Expires at')->dateTime(),
                TextColumn::make('ended_at')->label('Ended at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                RevokeApprovedSupportSessionAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewSupportSession::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No approved support sessions found')
            ->defaultSort('started_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportSessions::route('/'),
            'view' => ViewSupportSession::route('/{firmUuid}/{id}'),
        ];
    }
}
