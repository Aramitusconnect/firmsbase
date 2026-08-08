<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions;

use App\Enums\TrustLedgerEntryType;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustLedgerEntry;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustChargebackService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * ReportChargebackAction — "Report Chargeback" row/header action on
 * TrustLedgerEntryResource, wired directly to
 * TrustChargebackService::report() (the first of the report -> reverse
 * -> resolve Chargeback Actions). Visible only for a Deposit-type
 * entry, matching report()'s own guard exactly (`$originalEntry->
 * entry_type !== TrustLedgerEntryType::Deposit` throws).
 */
class ReportChargebackAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'reportChargeback';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Report Chargeback');
        $this->modalHeading('Report a Chargeback');
        $this->modalSubmitActionLabel('Report');
        $this->icon(Heroicon::OutlinedExclamationTriangle);
        $this->color('danger');

        $this->schema([
            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->required()
                ->default(fn (TrustLedgerEntry $record): float => abs($record->amount_cents) / 100),
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);

        $this->visible(function (TrustLedgerEntry $record): bool {
            if ($record->entry_type !== TrustLedgerEntryType::Deposit) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
        });

        $this->action(function (array $data, TrustLedgerEntry $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canRequest($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record, $data): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this entry.')->danger()->send();

                    return;
                }

                $fresh = TrustLedgerEntry::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustChargebackService::class)->report(
                        $firmUser->firm,
                        $fresh,
                        $firmUser,
                        (int) round(((float) $data['amount']) * 100),
                        (string) $data['reason'],
                    );
                    Notification::make()->title('Chargeback reported')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not report chargeback')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
