<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustRefundRequestStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustRefundRequest;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustRefundRequestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * CompleteRefundAction — row action on RefundRequestsRelationManager,
 * wired directly to TrustRefundRequestService::complete() (the third of
 * the request -> approve/deny -> complete Refund Actions). Visible only
 * for an Approved request.
 */
class CompleteRefundAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'completeRefund';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Complete');
        $this->icon(Heroicon::OutlinedBanknotes);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Posts the Refund ledger entry and recomputes the cached balance.');

        $this->visible(function (TrustRefundRequest $record): bool {
            if ($record->status !== TrustRefundRequestStatus::Approved) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (TrustRefundRequest $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this refund request.')->danger()->send();

                    return;
                }

                $fresh = TrustRefundRequest::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustRefundRequestService::class)->complete($firmUser->firm, $fresh, $firmUser);
                    Notification::make()->title('Refund completed')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not complete refund')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
