<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\Pages;

use App\Filament\Firm\Resources\PaymentPlanResource;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\ActivatePaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\CancelPaymentPlanAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\MarkPaymentPlanDefaultedAction;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\RenegotiatePaymentPlanAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewPaymentPlan — read-only Infolist only (no `form()` on
 * PaymentPlanResource at all). The `supersedes`/`supersededBy` lineage
 * is surfaced explicitly here so a renegotiated plan's history is
 * always visible — see RenegotiatePaymentPlanAction's own docblock for
 * why this matters.
 */
class ViewPaymentPlan extends ViewRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActivatePaymentPlanAction::make(),
            RenegotiatePaymentPlanAction::make(),
            CancelPaymentPlanAction::make(),
            MarkPaymentPlanDefaultedAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Plan')
                ->columns(2)
                ->schema([
                    TextEntry::make('id')->label('Plan')->formatStateUsing(fn ($state): string => "#{$state}"),
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "Invoice #{$state}"),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'active' => 'success',
                            'completed' => 'primary',
                            'draft' => 'gray',
                            'paused' => 'warning',
                            'renegotiated' => 'info',
                            'defaulted', 'cancelled' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('total_cents')
                        ->label('Total')
                        ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                    TextEntry::make('installment_count')->label('Installments'),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('supersedes.id')
                        ->label('Supersedes')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : "Plan #{$state} (renegotiated from)"),
                    TextEntry::make('supersededBy')
                        ->label('Superseded By')
                        ->state(function ($record): string {
                            $successor = $record->supersededBy()->first();

                            return $successor === null ? '—' : "Plan #{$successor->id} (renegotiated into)";
                        }),
                    TextEntry::make('activated_at')->dateTime()->placeholder('—'),
                    TextEntry::make('renegotiated_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('defaulted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Created At')->dateTime(),
                ]),
        ]);
    }
}
