<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustLedgerStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustLedgerService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * CloseTrustLedgerAction — wired directly to TrustLedgerService::close().
 * Visible for an Active or Frozen ledger (Closed is terminal, same
 * reasoning as CloseTrustAccountAction).
 */
class CloseTrustLedgerAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'closeTrustLedger';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Close');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Closes this client ledger. This is terminal — there is no "reopen" path.');

        $this->visible(function (TrustLedger $record): bool {
            if (! in_array($record->status, [TrustLedgerStatus::Active, TrustLedgerStatus::Frozen], true)) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (TrustLedger $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this ledger.')->danger()->send();

                    return;
                }

                $fresh = TrustLedger::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustLedgerService::class)->close($firmUser->firm, $fresh);
                    Notification::make()->title('Ledger closed')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not close ledger')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
