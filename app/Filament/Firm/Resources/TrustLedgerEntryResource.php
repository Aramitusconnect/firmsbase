<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\TrustLedgerEntryType;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ReportChargebackAction;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Pages\ListTrustLedgerEntries;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Pages\ViewTrustLedgerEntry;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\Services\TenantContextService;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * TrustLedgerEntryResource — Firm Feature Manifest §7 (Trust/IOLTA).
 * List + View pages ONLY — NO Create/Edit page anywhere (rule #1: the
 * model's own `booted()` guard already blocks update/delete on an
 * existing row, but a raw unguarded `create()` is still a real
 * vulnerability that guard doesn't catch, so this Resource never
 * exposes a generic model-bound form at all). Every entry is posted
 * only by TrustDepositService::post()/TrustTransferRequestService::
 * apply()/TrustRefundRequestService::complete()/
 * TrustHighRiskAdjustmentService::secondApprove()/
 * TrustLedgerEntryReversalService::reverse() — each already exposed as
 * its own dedicated Action on TrustLedgerResource/this Resource.
 *
 * `getEloquentQuery()` override: `trust_ledger_entries` deliberately
 * does NOT use the `BelongsToTenant` trait (see TrustLedgerEntry's own
 * model docblock and TenantSafeTrustPolicyService's docblock) — there
 * is no global Eloquent scope narrowing this model to the current firm
 * at all, in contrast to every other Filament Resource in this panel.
 * This override is the app-layer half of this table's tenant guard
 * (the other half is FORCE RLS at the database layer, which only
 * applies once `app.current_firm_id` is actually set for the current
 * database session) — it filters explicitly by the acting FirmUser's
 * own `firm_id`, unconditionally, on every query this Resource ever
 * issues (List, View, filters, global search), rather than relying on
 * ambient tenant context that Livewire AJAX interactions are not
 * guaranteed to carry (see ScopesQueriesToActiveFirm's own docblock).
 */
class TrustLedgerEntryResource extends Resource
{
    protected static ?string $model = TrustLedgerEntry::class;

    protected static ?string $slug = 'trust-ledger-entries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Trust Ledger Entries';

    protected static string|\UnitEnum|null $navigationGroup = 'Trust Accounting';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmTrustEligible();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmTrustEligible();
    }

    private static function isFirmTrustEligible(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(TrustEligibilityService::class)->isEligible($firmUser->firm)
            && app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
    }

    public static function getEloquentQuery(): Builder
    {
        $firmUser = Auth::user()?->activeFirmUser();
        $firmId = $firmUser?->firm_id ?? 0;

        return parent::getEloquentQuery()->where('trust_ledger_entries.firm_id', $firmId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('posted_at')->label('Posted')->dateTime()->sortable(),
                TextColumn::make('trustLedger.client.display_name')->label('Client')->placeholder('—'),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => ($state < 0 ? '-$' : '$').number_format(abs($state) / 100, 2))
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('reverses_entry_id')->label('Reverses Entry')->placeholder('—'),
            ])
            ->defaultSort('posted_at', 'desc')
            ->filters([
                SelectFilter::make('entry_type')
                    ->options(fn (): array => collect(TrustLedgerEntryType::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('trust_ledger_id')
                    ->label('Ledger')
                    ->options(function (): array {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null) {
                            return [];
                        }

                        return app(TenantContextService::class)->runWithFirmContext(
                            (int) $firmUser->firm_id,
                            fn () => TrustLedger::query()
                                ->with('client')
                                ->get()
                                ->mapWithKeys(fn (TrustLedger $ledger): array => [
                                    $ledger->id => trim(($ledger->client?->display_name ?? 'Ledger').' — #'.$ledger->id),
                                ])
                                ->all(),
                        );
                    }),
            ])
            ->recordActions([
                ReportChargebackAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrustLedgerEntries::route('/'),
            'view' => ViewTrustLedgerEntry::route('/{record}'),
        ];
    }
}
