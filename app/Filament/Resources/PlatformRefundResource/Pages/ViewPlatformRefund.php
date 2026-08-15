<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformRefundResource\Pages;

use App\Enums\PlatformRefundStatus;
use App\Filament\Resources\PlatformRefundResource;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * ViewPlatformRefund — the standard Filament ViewRecord page (ordinary
 * {record}/uuid route-model-binding, no RLS workaround needed). ZERO
 * header actions — see PlatformRefundResource's own docblock.
 *
 * Hosts TWO clearly, separately labeled sections beyond the refund's
 * own detail, per this phase's explicit instruction:
 *  1. "About 'Issue Refund'" — explains why refund EXECUTION is not
 *     available here (the balance-validation logic in
 *     PlatformRefundService::refund() is real, but the money-movement
 *     step is always simulated via FakeStripeGateway).
 *  2. "Credits" — an honest, standalone disclosure that NO credit-
 *     balance or credit-ledger system exists at all in this codebase
 *     for platform billing (no model, no table, no service — confirmed
 *     by the Phase 3 architecture investigation). This is not a page
 *     that failed to load; there is nothing to display because no
 *     backend concept exists yet. See PlatformRefundResourceTest's own
 *     empty-state test for this section.
 */
class ViewPlatformRefund extends ViewRecord
{
    protected static string $resource = PlatformRefundResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Refund')
                ->columns(2)
                ->schema([
                    TextEntry::make('payment.uuid')->label('Payment')->placeholder('—'),
                    TextEntry::make('payment.billingAccount.name')->label('Billing account')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PlatformRefundStatus $state): string => Str::headline($state->value)),
                    TextEntry::make('amount_cents')->label('Amount')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state)),
                    TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('gateway_refund_ref')->label('Gateway refund ref')->placeholder('—'),
                    TextEntry::make('requested_at')->label('Requested at')->dateTime(),
                    TextEntry::make('processed_at')->label('Processed at')->dateTime()->placeholder('—'),
                ]),
            Section::make('About "Issue Refund"')
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'No "Issue Refund" action exists anywhere in this console. PlatformRefundService::refund() '.
                        'has real, safe balance-validation logic — it holds a row lock on the payment, sums the '.
                        'already-completed refunds against it, and rejects anything above the remaining refundable '.
                        'balance — but the actual money-movement step always calls a StripeGateway, and this '.
                        'codebase has no production-capable implementation of that interface. In staging and '.
                        'production the container resolves it to UnavailablePaymentGateway, which throws rather '.
                        'than returning a fabricated success; only the test suite (and a local machine that has '.
                        'explicitly opted in) gets the simulated FakeStripeGateway. A refund therefore cannot '.
                        'execute from this console at all, and an "Issue Refund" button here would be a false '.
                        'financial affordance.'
                    ),
                ]),
            Section::make('Credits')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->description('This is a separate concept from refunds — read below.')
                ->schema([
                    Text::make(
                        'No credit-balance or credit-ledger system exists in this codebase for platform billing. '.
                        'There is no Credit model, no credits table, and no service method anywhere in this domain '.
                        'that issues or tracks a standalone account credit (as opposed to a refund against a '.
                        'specific payment, shown above). This is not a page that failed to load — there is nothing '.
                        'to display here because no backend concept exists yet.'
                    ),
                ]),
        ]);
    }
}
