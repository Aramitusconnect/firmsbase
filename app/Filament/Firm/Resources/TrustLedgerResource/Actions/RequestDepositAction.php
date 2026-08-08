<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\Matter;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustDepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * RequestDepositAction — "Request Deposit" header action on
 * ViewTrustLedger, wired directly to
 * TrustDepositService::requestDeposit() (the first of the three
 * request -> approve -> post Deposit Actions). Gated on
 * TrustAccessPolicyService::canRequest() — FirmOwner, Attorney, or
 * BillingStaff.
 */
class RequestDepositAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'requestDeposit';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Deposit');
        $this->modalHeading('Request a Trust Deposit');
        $this->modalSubmitActionLabel('Submit Request');
        $this->icon(Heroicon::OutlinedArrowDownCircle);
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
                ->options(fn (TrustLedger $record): array => self::firmScoped(fn () => Matter::query()
                    ->where('client_id', $record->client_id)
                    ->get()
                    ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
                    ->all()) ?? [])
                ->searchable()
                ->nullable()
                ->helperText('Only matters belonging to this ledger\'s client are shown.'),
        ]);

        $this->visible(function (TrustLedger $record): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
        });

        $this->action(function (array $data, TrustLedger $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canRequest($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record, $data): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this ledger.')->danger()->send();

                    return;
                }

                $fresh = TrustLedger::query()->where('id', $record->id)->firstOrFail();

                $matter = filled($data['matter_id'] ?? null)
                    ? Matter::query()->where('id', $data['matter_id'])->where('client_id', $fresh->client_id)->first()
                    : null;

                try {
                    app(TrustDepositService::class)->requestDeposit(
                        $firmUser->firm,
                        $fresh,
                        $firmUser,
                        (int) round(((float) $data['amount']) * 100),
                        $matter,
                    );
                    Notification::make()->title('Deposit requested')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not request deposit')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
