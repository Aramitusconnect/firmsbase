<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingAccountResource\Pages;

use App\Enums\PlatformSubscriptionStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\BillingAccountResource;
use App\Filament\Resources\FailedPaymentResource;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Filament\Resources\PlatformSubscriptionResource;
use App\Models\BillingAccount;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * ViewBillingAccount — the commercial position of one billing account,
 * and the navigation hub between that account and its commercial
 * records.
 *
 * ZERO header or record actions. This page observes; it does not
 * change commercial state. See BillingAccountResource's docblock.
 *
 * The aggregate figures come from the SAME
 * BillingAccountResource::withCommercialAggregates() SQL the list table
 * uses, re-run for this one record. That is deliberate: an operator who
 * sees an outstanding balance on the list and then opens the account
 * must not be shown a different number derived a different way.
 *
 * NO PAYMENT INSTRUMENT DATA. `payment_method_ref` is never rendered
 * here. There is no production gateway and therefore no real stored
 * instrument behind it, no card brand, no last four, no expiry, and no
 * payment-method health to report — showing the raw reference would
 * imply otherwise. The Payments section below states this plainly
 * rather than leaving an unexplained absence.
 */
class ViewBillingAccount extends ViewRecord
{
    protected static string $resource = BillingAccountResource::class;

    /**
     * Re-runs the shared aggregate query for this single record. Bounded
     * by definition — one row, aggregates computed in SQL.
     */
    private function aggregates(): BillingAccount
    {
        /** @var BillingAccount $record */
        $record = $this->getRecord();

        /** @var BillingAccount $withAggregates */
        $withAggregates = BillingAccountResource::withCommercialAggregates(
            BillingAccount::query()->whereKey($record->getKey())
        )->firstOrFail();

        return $withAggregates;
    }

    public function infolist(Schema $schema): Schema
    {
        $aggregates = $this->aggregates();
        $currency = PlatformBillingCommercialOverviewService::CURRENCY;

        return $schema->components([
            Section::make('Account')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('Billing account'),
                    TextEntry::make('organization.name')
                        ->label('Organization')
                        ->placeholder('Not part of a consolidated organization'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (RecordStatus $state): string => Str::headline($state->value))
                        ->color(fn (RecordStatus $state): string => match ($state) {
                            RecordStatus::Active => 'success',
                            RecordStatus::Inactive => 'warning',
                            RecordStatus::Archived => 'gray',
                        }),
                    TextEntry::make('uuid')->label('Reference')->fontFamily('mono'),
                    TextEntry::make('billing_email')->label('Billing email')->placeholder('—'),
                    TextEntry::make('bill_to_contact')->label('Bill-to contact')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('updated_at')->label('Last updated')->dateTime(),
                ]),

            Section::make('Commercial position')
                ->icon(Heroicon::OutlinedBanknotes)
                ->columns(2)
                ->schema([
                    Text::make(new HtmlString(
                        'Outstanding balance: <strong>'.e(MoneyDisplay::fromCents(
                            (int) ($aggregates->outstanding_cents ?? 0),
                            $currency,
                        )).'</strong>'
                    )),
                    Text::make(new HtmlString(
                        'Open invoices: <strong>'.e((string) ($aggregates->open_invoices_count ?? 0)).'</strong>'
                    )),
                    Text::make(new HtmlString(
                        'Live subscriptions: <strong>'.e((string) ($aggregates->live_subscriptions_count ?? 0)).'</strong>'
                    )),
                    Text::make(new HtmlString(
                        'Firms on this account: <strong>'.e((string) ($aggregates->firms_count ?? 0)).'</strong>'
                    )),
                    Text::make(new HtmlString(
                        'Failed payment attempts: <strong>'.e((string) ($aggregates->failed_attempts_count ?? 0)).'</strong>'
                    )),
                    Text::make(new HtmlString(
                        'Usage records: <strong>'.e((string) ($aggregates->usage_records_count ?? 0)).'</strong>'
                    )),
                    Text::make(
                        'Outstanding is the total of every issued, unpaid, non-void invoice on this account. There '.
                        'is no partial-payment netting in this domain: a platform invoice is charged for its full '.
                        'total and marked paid on success, so an unpaid invoice\'s outstanding amount is its '.
                        'total. All amounts are '.$currency.'; this schema has no currency column, so there is no '.
                        'second currency on any account.'
                    )->columnSpanFull(),
                ]),

            Section::make('Subscriptions')
                ->icon(Heroicon::OutlinedCreditCard)
                ->schema([
                    Text::make($this->subscriptionSummary($aggregates)),
                    Text::make(
                        'Supported subscription operations in this domain are: subscribe, add a subscription line '.
                        'item, cancel at period end, and cancel immediately. There is no plan change, scheduled '.
                        'plan change, pause, resume, cancellation-resume, or proration — no service implements '.
                        'any of them, so this console does not offer them anywhere.'
                    ),
                    $this->link('View this account\'s subscriptions', PlatformSubscriptionResource::getUrl()),
                ]),

            Section::make('Invoices and payments')
                ->icon(Heroicon::OutlinedDocumentText)
                ->schema([
                    Text::make(
                        'No payment instrument is shown for this account, and none is stored in any usable form. '.
                        'There is no production payment gateway configured, so there is no card, bank account, '.
                        'token, or payment-method status behind this account, and no payment-method health can be '.
                        'reported. Nothing on this page exposes a gateway reference, credential, or secret.'
                    ),
                    Text::make(
                        'An invoice on this account becomes Paid only through a gateway-confirmed payment. There '.
                        'is no "Mark Paid" or manual external-payment recording anywhere in this console: the '.
                        'invoice record has no field distinguishing a gateway-confirmed payment from an '.
                        'administrative override, so a manually-set paid status would be indistinguishable from a '.
                        'real one in every downstream query — including commission eligibility.'
                    ),
                    $this->link('View this account\'s invoices', PlatformInvoiceResource::getUrl()),
                    $this->link('View failed payment attempts', FailedPaymentResource::getUrl()),
                ]),

            Section::make('Credits')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'This account has no credit balance, because credits do not exist in this platform. There '.
                        'is no Credit model, credits table, or credit service anywhere in this codebase — a credit '.
                        'cannot be issued, applied, or tracked against any account. This is a missing capability, '.
                        'not a zero balance. Refunds, which return money already collected rather than reducing '.
                        'what is owed, are a separate and real concept and are listed under Refunds.'
                    ),
                ]),
        ]);
    }

    private function subscriptionSummary(BillingAccount $aggregates): string
    {
        $live = (int) ($aggregates->live_subscriptions_count ?? 0);

        if ($live === 0) {
            return 'This account has no live subscription (none in '.
                collect(BillingAccountResource::LIVE_SUBSCRIPTION_STATUSES)
                    ->map(fn (string $status): string => Str::headline($status))
                    ->join(', ', ' or ').
                ' status). It may be a new account, or one whose subscriptions have all ended.';
        }

        $trialing = (int) BillingAccount::query()
            ->whereKey($aggregates->getKey())
            ->withCount([
                'platformSubscriptions as trialing_count' => fn (Builder $q) => $q
                    ->where('status', PlatformSubscriptionStatus::Trialing->value),
            ])
            ->value('trialing_count');

        return 'This account has '.$live.' live subscription'.($live === 1 ? '' : 's').
            ($trialing > 0 ? ', of which '.$trialing.' '.($trialing === 1 ? 'is' : 'are').' still trialing' : '').
            '.';
    }

    private function link(string $label, string $url): Text
    {
        return Text::make(new HtmlString(
            '<a href="'.e($url).'" class="fi-link">'.e($label).' &rarr;</a>'
        ));
    }
}
