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
 * ApproveTransferAction — row action on TransferRequestsRelationManager,
 * wired directly to TrustTransferRequestService::approveTransfer().
 * Visible only while the request is Requested/PendingApproval (matches
 * approveTransfer()'s own guard exactly).
 */
class ApproveTransferAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'approveTransfer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();

        $this->visible(function (TrustTransferRequest $record): bool {
            if (! in_array($record->status, [TrustTransferRequestStatus::Requested, TrustTransferRequestStatus::PendingApproval], true)) {
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
                    app(TrustTransferRequestService::class)->approveTransfer($firmUser->firm, $fresh, $firmUser);
                    Notification::make()->title('Transfer approved')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not approve transfer')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
