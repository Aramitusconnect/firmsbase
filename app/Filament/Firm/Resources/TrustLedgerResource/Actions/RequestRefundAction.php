<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\Matter;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustRefundRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;

/**
 * RequestRefundAction — "Request Refund" header action on
 * RefundRequestsRelationManager (a tab on ViewTrustLedger), wired
 * directly to TrustRefundRequestService::requestRefund() (the first of
 * the request -> approve/deny -> complete Refund Actions).
 */
class RequestRefundAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'requestRefund';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Refund');
        $this->modalHeading('Request a Trust Refund to Client');
        $this->modalSubmitActionLabel('Submit Request');
        $this->icon(Heroicon::OutlinedReceiptRefund);
        $this->color('primary');

        $this->schema([
            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->required(),
            Select::make('matter_id')
                ->label('Matter (optional)')
                ->options(fn (RelationManager $livewire): array => self::matterOptions($livewire))
                ->searchable()
                ->nullable(),
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

                $matter = filled($data['matter_id'] ?? null)
                    ? Matter::query()->where('id', $data['matter_id'])->where('client_id', $fresh->client_id)->first()
                    : null;

                try {
                    app(TrustRefundRequestService::class)->requestRefund(
                        $firmUser->firm,
                        $fresh,
                        $firmUser,
                        (int) round(((float) $data['amount']) * 100),
                        $matter,
                    );
                    Notification::make()->title('Refund requested')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not request refund')->body($e->getMessage())->danger()->send();
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
}
