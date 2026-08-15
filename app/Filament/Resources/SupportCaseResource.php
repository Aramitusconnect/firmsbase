<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Filament\Actions\Platform\ExpireSupportCaseAction;
use App\Filament\Resources\SupportCaseResource\Pages\ListSupportCases;
use App\Filament\Resources\SupportCaseResource\Pages\ViewSupportCase;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
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
 * SupportCaseResource — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Support" category). Global, cross-firm oversight of
 * `support_access_requests` rows — the request/lifecycle-status facet
 * of the same underlying support-access workflow SupportSessionResource
 * (Approved Support Sessions) also covers. See
 * PlatformSupportAccessDirectoryService's own docblock for the full
 * architectural rationale (mirrors PlatformIntegrationCrossFirmDirectoryService's
 * per-firm-loop pattern through the same
 * PlatformFirmIntegrationBoundedAccessService chokepoint Checkpoint
 * 11's own single-firm support-access actions already use).
 *
 * "Firm Requests" was folded into this module and dropped as a
 * separate nav item (human-confirmed decision) — there is no separate
 * backend concept for it; it would have been a near-duplicate built
 * from this exact same SupportAccessRequest data.
 *
 * Deliberately List+View only, with exactly ONE mutating action
 * (Expire, via ExpireSupportCaseAction). No approve/deny action exists
 * or ever will — see ExpireSupportCaseAction's own docblock for the
 * full "genuine architectural boundary, not a gap" reasoning, and
 * SupportCaseResourceTest's own positive-proof tests (mirroring
 * ConflictResourceTest's established "no such action exists" pattern).
 */
class SupportCaseResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = SupportAccessRequest::class;

    protected static ?string $slug = 'support-cases';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    /**
     * Prompt 6 navigation-truth correction. This resource reads
     * `support_access_requests` and nothing else. There is no SupportCase
     * model, table, service or ticket domain anywhere in this codebase —
     * "Support Cases" named a domain that does not exist, and invited
     * operators to look for case identifiers, owners and statuses that
     * were never there. The nav item now says what the records are.
     * The class/slug/namespace are deliberately NOT renamed: they are
     * technical identifiers with existing test and route references, and
     * only user-visible branding is in scope here.
     */
    protected static ?string $navigationLabel = 'Access Requests';

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 60;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        // Deliberately reuses the existing canAccessIntegrationOversight()
        // gate rather than a new read gate — see
        // PlatformStaffAccessPolicyService::SUPPORT_ACCESS_MANAGEMENT_ROLES'
        // own docblock for the full reasoning (this resource's read path
        // shares PlatformFirmIntegrationBoundedAccessService's chokepoint
        // and governed-SupportAgent-session semantics with Checkpoint
        // 11's existing single-firm actions).
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

                $rows = app(PlatformSupportAccessDirectoryService::class)->listSupportCases($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                    'access_type' => $filters['access_type']['value'] ?? null,
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
                    ->options(collect(SupportAccessRequestStatus::cases())
                        ->mapWithKeys(fn (SupportAccessRequestStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('access_type')
                    ->label('Access type')
                    ->options(collect(SupportAccessType::cases())
                        ->mapWithKeys(fn (SupportAccessType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('reference')->label('Request')->searchable()->copyable(),
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('requested_by_name')->label('Requested by')->placeholder('—'),
                TextColumn::make('access_type')
                    ->label('Access type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => $state === SupportAccessType::Emergency->value ? 'danger' : 'gray'),
                TextColumn::make('reason')->label('Reason')->limit(60)->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        SupportAccessRequestStatus::Approved->value => 'success',
                        SupportAccessRequestStatus::Requested->value => 'warning',
                        SupportAccessRequestStatus::Denied->value, SupportAccessRequestStatus::Revoked->value => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requested_duration_minutes')->label('Requested (min)')->alignEnd(),
                TextColumn::make('created_at')->label('Requested at')->dateTime(),
            ])
            ->recordActions([
                ExpireSupportCaseAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewSupportCase::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No support access requests found')
            ->emptyStateDescription('Standard-access approval and denial happen on the firm side, in the firm\'s own Support Access page — a firm owner decides, never platform staff. This console can view request status and mark stale requests Expired.')
            ->defaultSort('created_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportCases::route('/'),
            'view' => ViewSupportCase::route('/{firmUuid}/{id}'),
        ];
    }
}
