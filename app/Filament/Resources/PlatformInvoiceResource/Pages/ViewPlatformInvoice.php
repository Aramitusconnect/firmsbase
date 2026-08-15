<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformInvoiceResource\Pages;

use App\Enums\PlatformInvoiceStatus;
use App\Filament\Actions\Platform\FinalizePlatformInvoiceAction;
use App\Filament\Actions\Platform\VoidPlatformInvoiceAction;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Models\PlatformPaymentAttempt;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * ViewPlatformInvoice — the standard Filament ViewRecord page
 * (ordinary {record}/uuid route-model-binding — see
 * PlatformInvoiceResource's own docblock for why this is safe here,
 * unlike the FORCE-RLS'd cross-firm resources in the Integration
 * Operations Center pass). Finalize/Void live here as header actions,
 * mirroring PlatformAdministratorResource's own "mutations happen on
 * the View page, per-record" convention.
 *
 * A clearly-labeled notice explains why "Mark Paid" is intentionally
 * absent — see PlatformInvoiceResource's own docblock for the full
 * reasoning (paid status must only ever result from a real, gateway-
 * confirmed payment, never a bare admin override, to avoid a
 * phantom-paid invoice with no corresponding PlatformPayment row).
 */
class ViewPlatformInvoice extends ViewRecord
{
    protected static string $resource = PlatformInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FinalizePlatformInvoiceAction::make(),
            VoidPlatformInvoiceAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $currency = PlatformBillingCommercialOverviewService::CURRENCY;

        return $schema->components([
            Section::make('Invoice')
                ->columns(2)
                ->schema([
                    TextEntry::make('billingAccount.name')->label('Billing account'),
                    TextEntry::make('subscription.uuid')->label('Subscription')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PlatformInvoiceStatus $state): string => Str::headline($state->value)),
                    TextEntry::make('period_starts_at')->label('Period start')->date(),
                    TextEntry::make('period_ends_at')->label('Period end')->date(),
                    TextEntry::make('subtotal_cents')
                        ->label('Subtotal')
                        ->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state, $currency)),
                    /**
                     * Billing & Commercial Control Plane pass. The tax
                     * column exists on this table, but NOTHING in this
                     * codebase calculates tax: there is no tax rate,
                     * jurisdiction, tax-behaviour setting, or tax engine
                     * anywhere. A bare "0.00 USD" here would read as
                     * "tax was evaluated and came to nothing", which is
                     * a materially different claim from "tax is not
                     * calculated at all". The helper text states which
                     * one is true.
                     */
                    TextEntry::make('tax_cents')
                        ->label('Tax')
                        ->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state, $currency))
                        ->helperText('Stored value only — this platform calculates no tax and applies no tax rate.'),
                    TextEntry::make('total_cents')
                        ->label('Total')
                        ->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state, $currency)),
                    TextEntry::make('due_at')->label('Due at')->dateTime()->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid at')->dateTime()->placeholder('Not paid'),
                    TextEntry::make('voided_at')->label('Voided at')->dateTime()->placeholder('—'),
                ]),

            Section::make('Amount due')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema([
                    Text::make($this->amountDueStatement($currency)),
                    Text::make(
                        'There is no partial-payment concept in this domain: an invoice is charged for its full '.
                        'total and marked paid on success, and this record stores no amount-paid or amount-due '.
                        'column of its own. So an unpaid invoice\'s amount due is exactly its total.'
                    ),
                    Text::make(
                        'No discount or credit line appears on this invoice, and none is shown as zero. Neither '.
                        'exists in this platform: there is no discount, coupon, or promotion concept at any '.
                        'level, and no Credit model, table, or service to apply a credit from.'
                    ),
                ]),

            $this->paymentsSection($currency),

            Section::make('Immutability')
                ->icon(Heroicon::OutlinedLockClosed)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'Nothing in this console edits an invoice\'s lines or totals. Totals are recalculated by '.
                        'the billing service from the invoice\'s own lines when a line is added; there is no form '.
                        'anywhere that lets an operator type a subtotal, tax, or total directly, before or after '.
                        'finalization.'
                    ),
                    Text::make(
                        'A later plan price change cannot alter this invoice. Invoice lines store their own '.
                        'amounts, and a plan\'s price is locked once any subscription references it — so what was '.
                        'billed stays what was billed.'
                    ),
                    Text::make(
                        'There is no credit note or amendment mechanism. An invoice that should not stand is '.
                        'voided, which leaves the original record intact and marked Void rather than deleting or '.
                        'rewriting it.'
                    ),
                ]),
            Section::make('About "Mark Paid"')
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->description('This is not an oversight — it is a deliberate limitation, explained below.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'There is no "Mark Paid" action anywhere in this console. PlatformInvoiceService::markPaid() '.
                        'is normally only ever called after a real (currently simulated) gateway payment confirmation. '.
                        'Exposing it as a direct admin action would let an invoice show "Paid" with no corresponding '.
                        'PlatformPayment row behind it — indistinguishable from a genuine, gateway-confirmed payment in '.
                        'every existing query and report, including commission-eligibility checks. A manual-override '.
                        'action would need its own provenance mechanism (recording who marked it paid and why, distinct '.
                        'from a system-confirmed payment) before it would be safe to build — that has not been added yet.'
                    ),
                    Text::make(
                        'The same reasoning rules out recording an external payment by hand. This invoice record '.
                        'has no field that could distinguish a gateway-confirmed payment from a manually-entered '.
                        'one or from an administrative correction, so any of the three would be indistinguishable '.
                        'from the others afterwards. Adding that provenance is a schema change, and it has not '.
                        'been made.'
                    ),
                ]),
        ]);
    }

    private function amountDueStatement(string $currency): string
    {
        /** @var PlatformInvoice $invoice */
        $invoice = $this->getRecord();

        return match ($invoice->status) {
            PlatformInvoiceStatus::Paid => 'Amount due: nothing — this invoice was paid on '.
                ($invoice->paid_at?->toDayDateTimeString() ?? 'an unrecorded date').'.',
            PlatformInvoiceStatus::Void => 'Amount due: nothing — this invoice was voided and is not collectable. '.
                'The record is retained as evidence of what was issued.',
            PlatformInvoiceStatus::Draft => 'Amount due: nothing yet — this invoice is a draft and has not been '.
                'issued. Its total is '.MoneyDisplay::fromCents($invoice->total_cents, $currency).'.',
            PlatformInvoiceStatus::Open, PlatformInvoiceStatus::PastDue => 'Amount due: '.
                MoneyDisplay::fromCents($invoice->total_cents, $currency).'.',
        };
    }

    /**
     * Payment history for this invoice — settled payments and every
     * attempt, successful or not. Both are bounded queries against the
     * invoice's own relations; an invoice's payment history is small by
     * construction (one attempt per collection try).
     */
    private function paymentsSection(string $currency): Section
    {
        /** @var PlatformInvoice $invoice */
        $invoice = $this->getRecord();

        $payments = $invoice->payments()->orderByDesc('attempted_at')->get();
        $attempts = $invoice->paymentAttempts()->orderByDesc('attempted_at')->get();

        $components = [];

        if ($payments->isEmpty()) {
            $components[] = Text::make('No payment has been recorded against this invoice.');
        } else {
            $components[] = Text::make(new HtmlString('<strong>Payments</strong>'));
            $components[] = UnorderedList::make(
                $payments->map(fn (PlatformPayment $payment): Text => Text::make(new HtmlString(
                    '<strong>'.e(MoneyDisplay::fromCents((int) $payment->amount_cents, $currency)).'</strong> — '.
                    e(Str::headline($payment->status->value)).
                    ' on '.e($payment->attempted_at?->toDayDateTimeString() ?? 'an unrecorded date')
                )))->all()
            );
        }

        if ($attempts->isEmpty()) {
            $components[] = Text::make('No collection attempt has been made against this invoice.');
        } else {
            $components[] = Text::make(new HtmlString('<strong>Collection attempts</strong>'));
            $components[] = UnorderedList::make(
                $attempts->map(fn (PlatformPaymentAttempt $attempt): Text => Text::make(new HtmlString(
                    'Attempt '.e((string) $attempt->attempt_number).': <strong>'.
                    e(Str::headline($attempt->status->value)).'</strong>'.
                    ($attempt->failure_reason === null ? '' : ' — '.e($attempt->failure_reason)).
                    ' on '.e($attempt->attempted_at?->toDayDateTimeString() ?? 'an unrecorded date')
                )))->all()
            );
        }

        $components[] = Text::make(
            'Gateway references are deliberately not shown here, and no card, bank, or token detail exists to '.
            'show — this platform has no production payment gateway configured, so no payment can be collected '.
            'or retried from this console.'
        );

        return Section::make('Payments')
            ->icon(Heroicon::OutlinedCreditCard)
            ->schema($components);
    }
}
