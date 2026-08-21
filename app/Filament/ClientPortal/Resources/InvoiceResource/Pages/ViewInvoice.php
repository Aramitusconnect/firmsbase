<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\InvoiceResource\Pages;

use App\Filament\ClientPortal\Resources\InvoiceResource;
use App\Models\ClientPortalUser;
use App\Models\Invoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ViewInvoice (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.6. Per-record authorization boundary — re-checks
 * InvoiceResource::isVisibleToPortalUser() directly, never trusting
 * InvoiceResource::getEloquentQuery()'s list-level filter alone (the
 * identical "list is UX filter, resolve step is the boundary" split
 * used throughout this mission's other Client Portal pages).
 *
 * Read-only Infolist: invoice number/date, line-item summary, total,
 * amount paid, balance due, status, and — when any Payment rows exist
 * for this invoice — a simple payment-history list. No Stripe/Finix/
 * payment-provider code is touched; every value below is a plain
 * display of already-recorded Invoice/InvoiceLine/Payment data via
 * existing Eloquent relations.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Invoice $record */
        $record = parent::resolveRecord($key);

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        abort_unless(
            $portalUser !== null && InvoiceResource::isVisibleToPortalUser($record, $portalUser),
            403,
        );

        return $record;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label('Invoice')->formatStateUsing(fn ($state): string => "#{$state}"),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'paid' => 'success',
                            'sent', 'approved' => 'info',
                            'partially_paid' => 'warning',
                            'void', 'written_off' => 'gray',
                            'refunded' => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('subtotal_cents')
                        ->label('Subtotal')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('total_cents')
                        ->label('Total')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('amount_paid_cents')
                        ->label('Amount Paid')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('balance_due')
                        ->label('Balance Due')
                        ->state(fn (Invoice $record): string => '$'.number_format(($record->total_cents - $record->amount_paid_cents) / 100, 2)),
                    TextEntry::make('issued_at')->label('Issued At')->dateTime()->placeholder('—'),
                    TextEntry::make('due_at')->label('Due At')->dateTime()->placeholder('—'),
                ]),

            Section::make('Line Items')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            TextEntry::make('description')->label('Description')->placeholder('—'),
                            TextEntry::make('quantity')->label('Qty')->placeholder('—'),
                            TextEntry::make('amount_cents')
                                ->label('Amount')
                                ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                        ])
                        ->columns(3),
                ])
                ->visible(fn (Invoice $record): bool => $record->lines->isNotEmpty()),

            Section::make('Payment History')
                ->schema([
                    RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            TextEntry::make('created_at')->label('Date')->dateTime(),
                            TextEntry::make('amount_cents')
                                ->label('Amount')
                                ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                        ])
                        ->columns(3),
                ])
                ->visible(fn (Invoice $record): bool => $record->payments->isNotEmpty()),
        ]);
    }
}
