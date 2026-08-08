<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\Client;
use App\Models\TrustAccount;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;
use App\Services\TrustLedgerService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * OpenTrustLedgerAction — "+ Open Ledger" header action (used both on
 * ListTrustLedgers and on TrustAccountResource's own
 * LedgersRelationManager), wired directly to TrustLedgerService::open()
 * — never a bare `TrustLedger::create()`. Like
 * TrustAccountService::open(), TrustLedgerService::open() takes no
 * FirmUser/actor parameter and performs no role check of its own — see
 * OpenTrustAccountAction's docblock for why this Action is therefore
 * gated on canApprove() rather than canRequest().
 */
class OpenTrustLedgerAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'openTrustLedger';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Open Ledger');
        $this->modalHeading('Open a Client Trust Ledger');
        $this->modalSubmitActionLabel('Open Ledger');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            Select::make('trust_account_id')
                ->label('Trust Account')
                ->options(fn (): array => self::firmScoped(fn () => TrustAccount::query()->orderBy('account_name')->pluck('account_name', 'id')->all()) ?? [])
                ->searchable()
                ->required(),
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => self::firmScoped(fn () => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all()) ?? [])
                ->searchable()
                ->required(),
        ]);

        $this->visible(function (): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                return false;
            }

            return app(TrustEligibilityService::class)->isEligible($firmUser->firm);
        });

        $this->action(function (array $data): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $data): void {
                $account = TrustAccount::query()->where('id', $data['trust_account_id'])->first();
                $client = Client::query()->where('id', $data['client_id'])->first();

                if ($account === null || (int) $account->firm_id !== (int) $firmUser->firm_id) {
                    Notification::make()->title('Could not open ledger')->body('The selected trust account could not be found for your firm.')->danger()->send();

                    return;
                }

                if ($client === null || (int) $client->firm_id !== (int) $firmUser->firm_id) {
                    Notification::make()->title('Could not open ledger')->body('The selected client could not be found for your firm.')->danger()->send();

                    return;
                }

                try {
                    app(TrustLedgerService::class)->open($firmUser->firm, $account, $client);
                    Notification::make()->title('Trust ledger opened')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not open trust ledger')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
