<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\OffboardingRequestStatus;
use App\Filament\Actions\Platform\AdvanceOffboardingRequestAction;
use App\Filament\Actions\Platform\CancelOffboardingRequestAction;
use App\Filament\Actions\Platform\CompleteOffboardingRequestAction;
use App\Filament\Resources\OffboardingRequestResource\Pages\ListOffboardingRequests;
use App\Filament\Resources\OffboardingRequestResource\Pages\ViewOffboardingRequest;
use App\Models\Firm;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformDataExportGovernanceDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
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
 * OffboardingRequestResource — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Data Exports module. Cross-firm List+View over
 * `offboarding_requests` with full state visibility + advance()/
 * complete()/cancel() actions — already correctly typed to PlatformAdmin
 * throughout OffboardingRequestService (see the Phase 4 architecture map
 * §B.4). Nested `offboarding_exports` (with the Verify action) are
 * shown on the View page — see ViewOffboardingRequest.
 *
 * Mutating gate: canManageDataExports(). Read gate: canAccessGovernance().
 *
 * FORCE RLS, firm-scoped only — queried exclusively via
 * PlatformDataExportGovernanceDirectoryService's per-firm-loop pattern.
 */
class OffboardingRequestResource extends Resource
{
    protected static ?string $model = OffboardingRequest::class;

    protected static ?string $slug = 'offboarding-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightOnRectangle;

    protected static ?string $navigationLabel = 'Offboarding';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed;
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

                return app(PlatformDataExportGovernanceDirectoryService::class)->listOffboardingRequests($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                ])->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->options(collect(OffboardingRequestStatus::cases())
                        ->mapWithKeys(fn (OffboardingRequestStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        OffboardingRequestStatus::Completed->value => 'success',
                        OffboardingRequestStatus::Cancelled->value, OffboardingRequestStatus::LegalHoldBlocked->value => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('reason')->label('Reason')->limit(60),
                TextColumn::make('requested_at')->label('Requested at')->dateTime(),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                AdvanceOffboardingRequestAction::make(),
                CompleteOffboardingRequestAction::make(),
                CancelOffboardingRequestAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewOffboardingRequest::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No offboarding requests found')
            ->defaultSort('requested_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffboardingRequests::route('/'),
            'view' => ViewOffboardingRequest::route('/{firmUuid}/{id}'),
        ];
    }
}
