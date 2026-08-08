<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustTransferRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * RequestTransferAction — "Request Transfer" header action on
 * TransferRequestsRelationManager (a tab on ViewTrustLedger), wired
 * directly to TrustTransferRequestService::requestTransfer() (the first
 * of the request -> approve/deny -> apply Transfer Actions). Both
 * matter_id and invoice_id are required — unlike Deposit/Refund/
 * Adjustment, a trust-to-invoice transfer has no ledger-only variant.
 */
class RequestTransferAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'requestTransfer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Transfer');
        $this->modalHeading('Request a Trust-to-Invoice Transfer');
        $this->modalSubmitActionLabel('Submit Request');
        $this->icon(Heroicon::OutlinedArrowsRightLeft);
        $this->color('primary');

        $this->schema([
            Select::make('matter_id')
                ->label('Matter')
                ->options(fn (RelationManager $livewire): array => self::matterOptions($livewire))
                ->searchable()
                ->required()
                ->live(),
            Select::make('invoice_id')
                ->label('Invoice')
                ->options(fn (Get $get): array => filled($get('matter_id')) ? self::invoiceOptions((int) $get('matter_id')) : [])
                ->searchable()
                ->required()
                ->helperText('Only invoices belonging to the selected matter are shown.'),
            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->required(),
        ]);

        $this->visible(function (RelationManager $livewire): bool {
            $ledger = $livewire->getOwnerRecord();

            if (! $ledger instanceof TrustLedger) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $ledger->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
        });

        $this->action(function (array $data, RelationManager $livewire): void {
            $ledger = $livewire->getOwnerRecord();
            $firmUser = self::activeFirmUser();

            if (
                $firmUser === null
                || ! $ledger instanceof TrustLedger
                || ! app(TrustAccessPolicyService::class)->canRequest($firmUser->role)
            ) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $ledger, $data): void {
                if ((int) $firmUser->firm_id !== (int) $ledger->firm_id) {
                    Notification::make()->title('You do not have access to this ledger.')->danger()->send();

                    return;
                }

                $fresh = TrustLedger::query()->where('id', $ledger->id)->firstOrFail();
                $matter = Matter::query()->where('id', $data['matter_id'])->where('client_id', $fresh->client_id)->first();
                $invoice = $matter !== null
                    ? Invoice::query()->where('id', $data['invoice_id'])->where('matter_id', $matter->id)->first()
                    : null;

                if ($matter === null || $invoice === null) {
                    Notification::make()->title('Could not request transfer')->body('The selected matter/invoice could not be found for this ledger.')->danger()->send();

                    return;
                }

                try {
                    app(TrustTransferRequestService::class)->requestTransfer(
                        $firmUser->firm,
                        $fresh,
                        $matter,
                        $invoice,
                        $firmUser,
                        (int) round(((float) $data['amount']) * 100),
                    );
                    Notification::make()->title('Transfer requested')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not request transfer')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }

    /**
     * @return array<int, string>
     */
    private static function matterOptions(RelationManager $livewire): array
    {
        $ledger = $livewire->getOwnerRecord();

        if (! $ledger instanceof TrustLedger) {
            return [];
        }

        return self::firmScoped(fn () => Matter::query()
            ->where('client_id', $ledger->client_id)
            ->get()
            ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
            ->all()) ?? [];
    }

    /**
     * @return array<int, string>
     */
    private static function invoiceOptions(int $matterId): array
    {
        return self::firmScoped(fn () => Invoice::query()
            ->where('matter_id', $matterId)
            ->get()
            ->mapWithKeys(fn (Invoice $invoice): array => [$invoice->id => "Invoice #{$invoice->id} — \$".number_format($invoice->total_cents / 100, 2)])
            ->all()) ?? [];
    }
}
