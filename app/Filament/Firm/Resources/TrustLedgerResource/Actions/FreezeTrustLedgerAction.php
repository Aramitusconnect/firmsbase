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
 * FreezeTrustLedgerAction — wired directly to TrustLedgerService::freeze().
 * Visible only for an Active ledger. See OpenTrustLedgerAction's
 * docblock for why this is gated on canApprove() rather than
 * canRequest().
 */
class FreezeTrustLedgerAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'freezeTrustLedger';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Freeze');
        $this->icon(Heroicon::OutlinedPauseCircle);
        $this->color('warning');
        $this->requiresConfirmation();
        $this->modalDescription('Freezes this client ledger. Blocks new deposits/withdrawals without losing history.');

        $this->visible(function (TrustLedger $record): bool {
            if ($record->status !== TrustLedgerStatus::Active) {
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
                    app(TrustLedgerService::class)->freeze($firmUser->firm, $fresh);
                    Notification::make()->title('Ledger frozen')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not freeze ledger')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
