<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustTransferRequestStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustTransferRequest;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustTransferRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * DenyTransferAction — row action on TransferRequestsRelationManager,
 * wired directly to TrustTransferRequestService::denyTransfer().
 */
class DenyTransferAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'denyTransfer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deny');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->modalHeading('Deny Transfer Request');
        $this->modalSubmitActionLabel('Deny');

        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);

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

        $this->action(function (array $data, TrustTransferRequest $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record, $data): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this transfer request.')->danger()->send();

                    return;
                }

                $fresh = TrustTransferRequest::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustTransferRequestService::class)->denyTransfer($firmUser->firm, $fresh, $firmUser, (string) $data['reason']);
                    Notification::make()->title('Transfer denied')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not deny transfer')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
