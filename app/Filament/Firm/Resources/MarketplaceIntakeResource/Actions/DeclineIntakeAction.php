<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions;

use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

/**
 * DeclineIntakeAction — Mission 3 (MyAttorney Conversion + AI Intake),
 * checkpoint 10. Unlike AcceptIntakeAction, this is allowed from ANY
 * pending-Firm-review state including ConflictReviewRequired (see
 * MarketplaceIntakeService::markDeclined()'s own docblock — declining
 * never risks acting on an unresolved conflict the way accepting
 * would). $reason is required free text, mirroring
 * RevokePaymentRequestAction's own Textarea('reason')->required() shape.
 */
class DeclineIntakeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'declineIntake';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Decline');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->modalHeading('Decline Prospect');
        $this->modalSubmitActionLabel('Decline');

        $this->schema([
            Textarea::make('decline_reason')
                ->label('Reason')
                ->required()
                ->rows(2),
        ]);

        $this->visible(function (MarketplaceIntake $record): bool {
            if (! $record->status->isPendingFirmReview()) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role);
        });

        $this->action(function (array $data, MarketplaceIntake $record, MarketplaceIntakeService $intakes): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $data, $firmUser, $intakes): void {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        $intakes->markDeclined($firm, $fresh, (string) ($data['decline_reason'] ?? ''), $firmUser);
                    },
                );
            } catch (InvalidArgumentException|RuntimeException $e) {
                Notification::make()->title('Could not decline prospect')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Prospect declined')->success()->send();
        });
    }
}
