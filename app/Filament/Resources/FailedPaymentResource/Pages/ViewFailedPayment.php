<?php

declare(strict_types=1);

namespace App\Filament\Resources\FailedPaymentResource\Pages;

use App\Enums\PlatformPaymentStatus;
use App\Filament\Resources\FailedPaymentResource;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Models\PlatformPaymentAttempt;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * ViewFailedPayment — the standard Filament ViewRecord page (ordinary
 * {record}/uuid route-model-binding, no RLS workaround needed). ZERO
 * header actions — see FailedPaymentResource's own docblock.
 *
 * Additionally surfaces, as read-only context, any PlatformPayment rows
 * in `Failed` status for the SAME invoice (real data via
 * `$record->invoice->payments()`, never a new read path/service) — this
 * is the "optionally surfacing PlatformPayment rows" the mission's own
 * spec mentioned, done here rather than as a second resource/tab (see
 * FailedPaymentResource's own docblock for that decision).
 */
class ViewFailedPayment extends ViewRecord
{
    protected static string $resource = FailedPaymentResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Failed Payment Attempt')
                ->columns(2)
                ->schema([
                    TextEntry::make('billingAccount.name')->label('Billing account')->placeholder('—'),
                    TextEntry::make('invoice.uuid')
                        ->label('Invoice')
                        ->placeholder('—')
                        ->url(fn (PlatformPaymentAttempt $record): ?string => $record->platform_invoice_id === null
                            ? null
                            : PlatformInvoiceResource::getUrl('view', ['record' => $record->invoice])),
                    TextEntry::make('attempt_number')->label('Attempt #'),
                    TextEntry::make('gateway_response_code')->label('Gateway response')->placeholder('—'),
                    TextEntry::make('failure_reason')->label('Failure reason')->placeholder('—'),
                    TextEntry::make('attempted_at')->label('Attempted at')->dateTime(),
                ]),
            Section::make('Related Failed Payment Record(s)')
                ->description('Any platform_payments rows in Failed status for this same invoice, shown for context only.')
                ->collapsible()
                ->schema([
                    UnorderedList::make(function (PlatformPaymentAttempt $record): array {
                        if ($record->invoice === null) {
                            return ['No invoice is linked to this attempt.'];
                        }

                        $failedPayments = $record->invoice->payments()
                            ->where('status', PlatformPaymentStatus::Failed)
                            ->orderBy('id')
                            ->get();

                        if ($failedPayments->isEmpty()) {
                            return ['No PlatformPayment row exists in Failed status for this invoice.'];
                        }

                        return $failedPayments
                            ->map(fn ($payment): string => sprintf(
                                '%s — %s — attempted %s',
                                MoneyDisplay::fromCents($payment->amount_cents),
                                $payment->gateway_payment_ref ?? '—',
                                $payment->attempted_at?->toDayDateTimeString() ?? '—',
                            ))
                            ->all();
                    }),
                ]),
            Section::make('About "Retry" and "Waive"')
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'No "Retry" action exists here because the only payment gateway in this codebase '.
                        '(FakeStripeGateway) cannot process a real payment — it is explicitly forbidden from making '.
                        'a real Stripe API call and is not even bound in the container. No "Waive" action exists '.
                        'because no such concept exists anywhere in PlatformPaymentAttemptStatus, '.
                        'PlatformPaymentStatus, or any platform-billing service method.'
                    ),
                ]),
        ]);
    }
}
