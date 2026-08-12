<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions;

use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ClearIntakeConflictReviewAction — Mission 3 (MyAttorney Conversion +
 * AI Intake), checkpoint 9. A human confirmation that the flagged
 * possible matches are not a real conflict, mirroring
 * ConflictCheckService::resolveResult()'s own "only a human may set a
 * terminal outcome" rule — this action itself never inspects or
 * displays the matched entity's own details (that stays a Firm
 * reviewer's own independent lookup); it only records that a
 * qualified reviewer cleared the flag.
 */
class ClearIntakeConflictReviewAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'clearIntakeConflictReview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Clear Conflict Review');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->modalHeading('Clear Conflict Review');
        $this->modalDescription('Confirms you have reviewed the flagged possible matches and this is not a real conflict of interest. This intake will return to Under Review.');
        $this->modalSubmitActionLabel('Clear');
        $this->requiresConfirmation();

        $this->visible(function (MarketplaceIntake $record): bool {
            if ($record->status !== MarketplaceIntakeStatus::ConflictReviewRequired) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canResolveConflictResult($firmUser->role);
        });

        $this->action(function (MarketplaceIntake $record, MarketplaceIntakeService $intakes): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canResolveConflictResult($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Only a Firm Owner or Attorney may clear a conflict review.')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $firmUser, $intakes): void {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        $intakes->clearConflictReview($firm, $fresh, $firmUser);
                    },
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not clear conflict review')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Conflict review cleared')->success()->send();
        });
    }
}
