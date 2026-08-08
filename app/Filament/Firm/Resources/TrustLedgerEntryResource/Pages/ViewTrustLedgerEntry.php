<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerEntryResource\Pages;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Filament\Firm\Resources\TrustLedgerEntryResource;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ReportChargebackAction;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ResolveChargebackAction;
use App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions\ReverseChargebackAction;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedgerEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewTrustLedgerEntry — read-only Infolist only (no `form()` anywhere
 * on TrustLedgerEntryResource — rule #1's model-level append-only
 * guard is matched here at the UI level too: there is no way to edit
 * any field of an existing entry from this page). "Chargeback History"
 * is a read-only RepeatableEntry (state closure, not a relation — see
 * ReverseChargebackAction's own docblock for why) rather than a
 * RelationManager.
 */
class ViewTrustLedgerEntry extends ViewRecord
{
    use ScopesQueriesToActiveFirm;

    protected static string $resource = TrustLedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReportChargebackAction::make(),
            ReverseChargebackAction::make(),
            ResolveChargebackAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trust Ledger Entry')
                ->columns(2)
                ->schema([
                    TextEntry::make('entry_type')
                        ->label('Type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('amount_cents')
                        ->label('Amount')
                        ->formatStateUsing(fn (int $state): string => ($state < 0 ? '-$' : '$').number_format(abs($state) / 100, 2)),
                    TextEntry::make('trustLedger.client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('reverses_entry_id')->label('Reverses Entry')->placeholder('—'),
                    TextEntry::make('sourcePayment.id')->label('Source Payment')->placeholder('—'),
                    TextEntry::make('posted_at')->label('Posted At')->dateTime(),
                ]),
            Section::make('Chargeback History')
                ->schema([
                    RepeatableEntry::make('chargebacks')
                        ->hiddenLabel()
                        ->state(fn (TrustLedgerEntry $record): array => $this->chargebackHistory($record))
                        ->schema([
                            TextEntry::make('status')->label('Status')->badge(),
                            TextEntry::make('amount')->label('Amount'),
                            TextEntry::make('reason')->label('Reason'),
                            TextEntry::make('reported_at')->label('Reported'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    /**
     * @return array<int, array{status: string, amount: string, reason: string, reported_at: string}>
     */
    private function chargebackHistory(TrustLedgerEntry $record): array
    {
        return self::firmScoped(function () use ($record): array {
            return TrustChargebackEvent::query()
                ->where('original_trust_ledger_entry_id', $record->id)
                ->orderByDesc('reported_at')
                ->get()
                ->map(fn (TrustChargebackEvent $event): array => [
                    'status' => str($event->status->value)->headline()->toString(),
                    'amount' => '$'.number_format($event->amount_cents / 100, 2),
                    'reason' => $event->reason,
                    'reported_at' => $event->reported_at?->toDateTimeString() ?? '—',
                ])
                ->all();
        }) ?? [];
    }
}
