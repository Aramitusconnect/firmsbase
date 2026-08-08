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
 * ResolveChargebackAction — "Resolve Chargeback" header action on
 * ViewTrustLedgerEntry, wired directly to
 * TrustChargebackService::resolve() (the third of the report -> reverse
 * -> resolve Chargeback Actions). See ReverseChargebackAction's
 * docblock for why the chargeback is resolved automatically rather than
 * via a picker.
 */
class ResolveChargebackAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'resolveChargeback';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve Chargeback');
        $this->requiresConfirmation();
        $this->modalDescription('Marks this already-reversed chargeback as Resolved. This does not itself move any money.');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->visible(function (TrustLedgerEntry $record): bool {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if (! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                return false;
            }

            return self::reversedChargeback($record) !== null;
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

                $chargeback = self::reversedChargeback($record);

                if ($chargeback === null) {
                    Notification::make()->title('Could not resolve chargeback')->body('No Reversed chargeback was found for this entry.')->danger()->send();

                    return;
                }

                try {
                    app(TrustChargebackService::class)->resolve($firmUser->firm, $chargeback, $firmUser);
                    Notification::make()->title('Chargeback resolved')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not resolve chargeback')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }

    private static function reversedChargeback(TrustLedgerEntry $record): ?TrustChargebackEvent
    {
        return self::firmScoped(function () use ($record): ?TrustChargebackEvent {
            $firmUser = self::activeFirmUser();

            return TrustChargebackEvent::query()
                ->where('firm_id', $firmUser?->firm_id)
                ->where('original_trust_ledger_entry_id', $record->id)
                ->where('status', TrustChargebackStatus::Reversed->value)
                ->orderByDesc('reported_at')
                ->first();
        });
    }
}
