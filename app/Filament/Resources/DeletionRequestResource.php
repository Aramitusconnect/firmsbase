<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DeletionRequestStatus;
use App\Filament\Actions\Platform\DenyDeletionAction;
use App\Filament\Actions\Platform\FirstApproveDeletionAction;
use App\Filament\Actions\Platform\RequestDeletionApprovalAction;
use App\Filament\Actions\Platform\SecondApproveDeletionAction;
use App\Filament\Actions\Platform\SubmitDeletionRequestForApprovalAction;
use App\Filament\Resources\DeletionRequestResource\Pages\ListDeletionRequests;
use App\Filament\Resources\DeletionRequestResource\Pages\ViewDeletionRequest;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformDeletionRequestDirectoryService;
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
 * DeletionRequestResource — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Deletion Requests module. Cross-firm List+View
 * over `deletion_requests` with a nested view of its `deletion_approvals`
 * record — the single most backend-complete module across all 11
 * Governance/Operations modules (see the Phase 4 architecture map §B.5):
 * a genuine two-person-approval workflow, fully wired, fully audited,
 * fully PlatformAdmin-typed already.
 *
 * Shows the full status lifecycle up to and including ReadyForExecution
 * — the TERMINAL state. There is NO execute()/delete() method anywhere
 * in this codebase for DeletionRequest; ReadyForExecution is a
 * deliberate dead end. This Resource and its Actions/Pages never label
 * it "deleted" — always "approved for execution."
 *
 * Mutating gate: canManageDeletionGovernance() — a NEW gate this phase
 * adds (verified against HighRiskPlatformChangePolicyService: it
 * enforces only "a reason is required" and "the second approver must
 * differ from the first," no role-based authorization of its own, so
 * this UI-layer gate is not redundant). Read gate: canAccessGovernance().
 *
 * FORCE RLS, firm-scoped only — queried exclusively via
 * PlatformDeletionRequestDirectoryService's per-firm-loop pattern.
 */
class DeletionRequestResource extends Resource
{
    protected static ?string $model = DeletionRequest::class;

    protected static ?string $slug = 'deletion-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?string $navigationLabel = 'Deletion Requests';

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

                return app(PlatformDeletionRequestDirectoryService::class)->list($admin, [
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
                    ->options(collect(DeletionRequestStatus::cases())
                        ->mapWithKeys(fn (DeletionRequestStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('subject_type')->label('Subject type'),
                TextColumn::make('subject_id')->label('Subject id'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        DeletionRequestStatus::ReadyForExecution->value => 'success',
                        DeletionRequestStatus::Denied->value, DeletionRequestStatus::LegalHoldBlocked->value, DeletionRequestStatus::Cancelled->value => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state === DeletionRequestStatus::ReadyForExecution->value
                        ? 'Approved for execution'
                        : ($state === null ? '—' : Str::headline($state))),
                TextColumn::make('approval_status')->label('Approval status')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Not yet requested' : Str::headline($state)),
                TextColumn::make('requested_at')->label('Requested at')->dateTime(),
            ])
            ->recordActions([
                SubmitDeletionRequestForApprovalAction::make(),
                RequestDeletionApprovalAction::make(),
                FirstApproveDeletionAction::make(),
                SecondApproveDeletionAction::make(),
                DenyDeletionAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewDeletionRequest::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No deletion requests found')
            ->emptyStateDescription('The terminal state here is "approved for execution" — this system never physically deletes the underlying record.')
            ->defaultSort('requested_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeletionRequests::route('/'),
            'view' => ViewDeletionRequest::route('/{firmUuid}/{id}'),
        ];
    }
}
