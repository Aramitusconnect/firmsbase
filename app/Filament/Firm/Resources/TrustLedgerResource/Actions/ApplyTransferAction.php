<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustTransferRequestStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustTransferRequest;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustTransferRequestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * ApplyTransferAction — row action on TransferRequestsRelationManager,
 * wired directly to TrustTransferRequestService::apply() (the third of
 * the request -> approve/deny -> apply Transfer Actions). Visible only
 * for an Approved request. apply() itself posts the WithdrawalToInvoice
 * ledger entry AND creates the resulting real Payment/invoice
 * application inside its own locked transaction — this Action never
 * duplicates any of that logic, it only calls the one method.
 */
class ApplyTransferAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'applyTransfer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Apply');
        $this->icon(Heroicon::OutlinedBanknotes);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Withdraws the approved amount from this trust ledger and applies it as a payment to the invoice.');

        $this->visible(function (TrustTransferRequest $record): bool {
            if ($record->status !== TrustTransferRequestStatus::Approved) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (TrustTransferRequest $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this transfer request.')->danger()->send();

                    return;
                }

                $fresh = TrustTransferRequest::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustTransferRequestService::class)->apply($firmUser->firm, $fresh, $firmUser);
                    Notification::make()->title('Transfer applied')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not apply transfer')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
