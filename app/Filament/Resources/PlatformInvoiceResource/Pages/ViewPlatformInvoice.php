<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformInvoiceResource\Pages;

use App\Enums\PlatformInvoiceStatus;
use App\Filament\Actions\Platform\FinalizePlatformInvoiceAction;
use App\Filament\Actions\Platform\VoidPlatformInvoiceAction;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
                    TextEntry::make('subtotal_cents')->label('Subtotal')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state)),
                    TextEntry::make('tax_cents')->label('Tax')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state)),
                    TextEntry::make('total_cents')->label('Total')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state)),
                    TextEntry::make('due_at')->label('Due at')->dateTime()->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid at')->dateTime()->placeholder('—'),
                    TextEntry::make('voided_at')->label('Voided at')->dateTime()->placeholder('—'),
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
                ]),
        ]);
    }
}
