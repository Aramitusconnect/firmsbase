<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerEntryResource\Actions;

use App\Enums\TrustChargebackStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedgerEntry;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustChargebackService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * ReverseChargebackAction — "Reverse Chargeback" header action on
 * ViewTrustLedgerEntry, wired directly to
 * TrustChargebackService::reverse() (the second of the report ->
 * reverse -> resolve Chargeback Actions). The chargeback to reverse is
 * resolved automatically — the most recent Reported
 * TrustChargebackEvent whose `original_trust_ledger_entry_id` matches
 * this entry — rather than asked of the user via a picker, since a
 * given entry realistically has at most one open chargeback at a time.
 * TrustChargebackEvent has no relation from TrustLedgerEntry to bind a
 * RelationManager to (see EntriesRelationManager's own docblock for the
 * same "no relation on a Trust model this task cannot modify" reasoning),
 * so this direct query mirrors ApproveDepositAction's established
 * pattern for the same structural constraint.
 */
class ReverseChargebackAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'reverseChargeback';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reverse Chargeback');
        $this->requiresConfirmation();
        $this->modalDescription('Posts an offsetting ChargebackReversal ledger entry. The original deposit entry is never mutated.');
        $this->icon(Heroicon::OutlinedArrowUpCircle);
        $this->color('warning');

        $this->visible(function (TrustLedgerEntry $record): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if (! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                return false;
            }

            return self::reportedChargeback($record) !== null;
        });

        $this->action(function (TrustLedgerEntry $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this entry.')->danger()->send();

                    return;
                }

                $chargeback = self::reportedChargeback($record);

                if ($chargeback === null) {
                    Notification::make()->title('Could not reverse chargeback')->body('No Reported chargeback was found for this entry.')->danger()->send();

                    return;
                }

                try {
                    app(TrustChargebackService::class)->reverse($firmUser->firm, $chargeback, $firmUser);
                    Notification::make()->title('Chargeback reversed')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not reverse chargeback')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }

    private static function reportedChargeback(TrustLedgerEntry $record): ?TrustChargebackEvent
    {
        return self::firmScoped(function () use ($record): ?TrustChargebackEvent {
            $firmUser = self::activeFirmUser();

            return TrustChargebackEvent::query()
                ->where('firm_id', $firmUser?->firm_id)
                ->where('original_trust_ledger_entry_id', $record->id)
                ->where('status', TrustChargebackStatus::Reported->value)
                ->orderByDesc('reported_at')
                ->first();
        });
    }
}
