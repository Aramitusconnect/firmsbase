<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Pages;

use App\Filament\Firm\Resources\InvoiceResource;
use App\Filament\Firm\Resources\InvoiceResource\Actions\AddManualChargeAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\ApproveInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SendInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\SubmitInvoiceForReviewAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\VoidInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\WriteOffInvoiceAction;
use App\Models\Invoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewInvoice — read-only Infolist only (no `form()` on InvoiceResource
 * at all — every value below is a plain display of an already-derived
 * column; nothing here is editable). The same five state-transition
 * Actions available as table row actions on ListInvoices are also
 * exposed here as header actions (each Action's own `visible()`
 * closure already gates on the record's current status + role, so
 * duplicating them here is safe — Filament resolves `$record` from the
 * page's own bound model either way).
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddManualChargeAction::make(),
            SubmitInvoiceForReviewAction::make(),
            ApproveInvoiceAction::make(),
            SendInvoiceAction::make(),
            VoidInvoiceAction::make(),
            WriteOffInvoiceAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label('Invoice')->formatStateUsing(fn ($state): string => "#{$state}"),
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('invoice_type')
                        ->label('Type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
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
                    TextEntry::make('subtotal_cents')
                        ->label('Subtotal')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('total_cents')
                        ->label('Total')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('amount_paid_cents')
                        ->label('Amount Paid')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('outstanding_balance')
                        ->label('Outstanding Balance')
                        ->state(fn (Invoice $record): int => $record->total_cents - $record->amount_paid_cents)
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('issued_at')->label('Issued At')->dateTime()->placeholder('—'),
                    TextEntry::make('due_at')->label('Due At')->dateTime()->placeholder('—'),
                    TextEntry::make('sent_at')->label('Sent At')->dateTime()->placeholder('—'),
                    TextEntry::make('voided_at')->label('Voided At')->dateTime()->placeholder('—'),
                    TextEntry::make('createdBy.name')->label('Created By')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created At')->dateTime(),
                ]),
        ]);
    }
}
