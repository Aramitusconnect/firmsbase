<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Actions;

use App\Enums\TrustRefundRequestStatus;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Models\TrustRefundRequest;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustRefundRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * DenyRefundAction — row action on RefundRequestsRelationManager, wired
 * directly to TrustRefundRequestService::denyRefund().
 */
class DenyRefundAction extends Action
{
    use ScopesQueriesToActiveFirm;

    public static function getDefaultName(): ?string
    {
        return 'denyRefund';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deny');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->modalHeading('Deny Refund Request');
        $this->modalSubmitActionLabel('Deny');

        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);

        $this->visible(function (TrustRefundRequest $record): bool {
            if (! in_array($record->status, [TrustRefundRequestStatus::Requested, TrustRefundRequestStatus::PendingApproval], true)) {
                return false;
            }

            $firmUser = self::activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TrustAccessPolicyService::class)->canApprove($firmUser->role);
        });

        $this->action(function (array $data, TrustRefundRequest $record): void {
            $firmUser = self::activeFirmUser();

            if ($firmUser === null || ! app(TrustAccessPolicyService::class)->canApprove($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            self::firmScoped(function () use ($firmUser, $record, $data): void {
                if ((int) $firmUser->firm_id !== (int) $record->firm_id) {
                    Notification::make()->title('You do not have access to this refund request.')->danger()->send();

                    return;
                }

                $fresh = TrustRefundRequest::query()->where('id', $record->id)->firstOrFail();

                try {
                    app(TrustRefundRequestService::class)->denyRefund($firmUser->firm, $fresh, $firmUser, (string) $data['reason']);
                    Notification::make()->title('Refund denied')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not deny refund')->body($e->getMessage())->danger()->send();
                }
            });
        });
    }
}
