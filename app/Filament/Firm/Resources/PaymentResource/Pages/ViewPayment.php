<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentResource\Pages;

use App\Filament\Firm\Resources\PaymentResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewPayment — read-only Infolist only (no `form()` on PaymentResource
 * at all — manifest rule #4: never expose raw CRUD on a Payment row).
 * Every value below is a plain display of an already-derived column;
 * nothing here is editable.
 */
class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment')
                ->columns(2)
                ->schema([
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "Invoice #{$state}"),
                    TextEntry::make('paymentPlanInstallment.sequence')->label('Installment')->formatStateUsing(fn ($state): string => $state === null ? '—' : "Installment #{$state}"),
                    TextEntry::make('amount_cents')
                        ->label('Amount')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('payment_method')
                        ->label('Method')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('payment_classification')
                        ->label('Classification')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'succeeded' => 'success',
                            'initiated', 'pending', 'classified' => 'info',
                            'blocked', 'failed', 'disputed', 'reversed' => 'danger',
                            'refunded', 'partially_refunded' => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('external_reference')->label('External Reference')->placeholder('—'),
                    TextEntry::make('manualPaymentRecord.method_reference')->label('Method Reference')->placeholder('—'),
                    TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('manualPaymentRecord.notes')->label('Notes')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('recordedBy.name')->label('Recorded By')->placeholder('—'),
                    TextEntry::make('created_at')->label('Recorded At')->dateTime(),
                ]),
        ]);
    }
}
