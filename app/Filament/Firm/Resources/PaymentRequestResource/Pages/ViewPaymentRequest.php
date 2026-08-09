<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Pages;

use App\Filament\Firm\Resources\PaymentRequestResource;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\ActivatePaymentRequestAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\CopyPaymentLinkAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\RevokePaymentRequestAction;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\ShowQrCodeAction;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewPaymentRequest — master prompt item 12: "Firm users see request/
 * purpose/client-matter/amount/provider-evidence/proposed-classification/
 * final-accounting-result/audit-history, no raw provider secrets."
 * Read-only Infolist only — no `form()`/EditRecord page exists at all
 * (see PaymentRequestResource's own docblock). "Audit History" is a
 * read-only RepeatableEntry over payment_request_events, mirroring
 * ViewTrustLedgerEntry's own "Chargeback History" state-closure
 * pattern; it deliberately never surfaces provider_response_json — the
 * write side already allowlists it to {status,id,failure_reason}
 * (PaymentRequestCheckoutService::redactProviderResponse()), and this
 * view goes further by not rendering that column at all, only the
 * already-safe provider_transaction_id.
 */
class ViewPaymentRequest extends ViewRecord
{
    protected static string $resource = PaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActivatePaymentRequestAction::make(),
            CopyPaymentLinkAction::make(),
            ShowQrCodeAction::make(),
            RevokePaymentRequestAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Request')
                ->columns(2)
                ->schema([
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('purpose')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('amount_rule')
                        ->label('Amount Rule')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('requested_amount_cents')
                        ->label('Requested Amount')
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$'.number_format($state / 100, 2)),
                    TextEntry::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                    TextEntry::make('paymentPlanInstallment.id')->label('Installment')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                    TextEntry::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
                    TextEntry::make('createdBy.user.name')->label('Created By')->placeholder('—'),
                    TextEntry::make('revokedBy.user.name')->label('Revoked By')->placeholder('—'),
                    TextEntry::make('revoke_reason')->label('Revoke Reason')->placeholder('—'),
                ]),
            Section::make('Provider Evidence & Accounting Result')
                ->columns(2)
                ->schema([
                    TextEntry::make('provider_transaction_id')->label('Provider Transaction')->placeholder('—'),
                    TextEntry::make('paid_amount_cents')
                        ->label('Paid Amount')
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$'.number_format($state / 100, 2)),
                    TextEntry::make('paid_at')->label('Paid At')->dateTime()->placeholder('—'),
                    TextEntry::make('payment.id')->label('Resulting Payment')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                    TextEntry::make('payment.payment_classification')
                        ->label('Accounting Classification')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : (is_object($state) ? (string) str($state->value)->headline() : (string) $state)),
                    TextEntry::make('payment.status')
                        ->label('Payment Status')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : (is_object($state) ? (string) str($state->value)->headline() : (string) $state)),
                    TextEntry::make('failure_reason')->label('Failure / Review Reason')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Audit History')
                ->schema([
                    RepeatableEntry::make('events')
                        ->hiddenLabel()
                        ->state(fn (PaymentRequest $record): array => $this->eventHistory($record))
                        ->schema([
                            TextEntry::make('event_type')->label('Event')->badge(),
                            TextEntry::make('amount')->label('Amount'),
                            TextEntry::make('provider_transaction_id')->label('Provider Txn'),
                            TextEntry::make('actor')->label('Actor'),
                            TextEntry::make('note')->label('Note'),
                            TextEntry::make('created_at')->label('When'),
                        ])
                        ->columns(6),
                ]),
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function eventHistory(PaymentRequest $record): array
    {
        return PaymentRequestEvent::query()
            ->where('payment_request_id', $record->id)
            ->with('actor.user')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PaymentRequestEvent $event): array => [
                'event_type' => str($event->event_type->value)->headline()->toString(),
                'amount' => $event->amount_cents === null ? '—' : '$'.number_format($event->amount_cents / 100, 2),
                'provider_transaction_id' => $event->provider_transaction_id ?? '—',
                'actor' => $event->actor?->user?->name ?? 'Payer / System',
                'note' => $event->note ?? '—',
                'created_at' => $event->created_at?->toDateTimeString() ?? '—',
            ])
            ->all();
    }
}
