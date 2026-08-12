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
 * AcceptIntakeAction — Mission 3 (MyAttorney Conversion + AI Intake),
 * checkpoint 10. The Firm's own commitment to proceed toward a
 * consultation with this prospect — calls
 * MarketplaceIntakeService::markAccepted() directly, which itself
 * refuses a ConflictReviewRequired intake (conflict review must be
 * cleared first; never bypassable from this action). Creates no
 * FirmLead/Client/Matter — conversion is checkpoint 11's own scope.
 *
 * Gated on ClientCrmAccessPolicyService::canConvertLead() — the same
 * ceiling ConvertLeadToClientAction uses, since accepting a prospect
 * is the same class of consequential decision as converting a lead,
 * not routine intake triage (MarkUnderReviewAction's own, wider
 * canManageLead() ceiling).
 */
class AcceptIntakeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'acceptIntake';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Accept & Request Consultation');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->modalHeading('Accept Prospect');
        $this->modalDescription('This firm will proceed toward a consultation with this prospect. This does not yet create a Client or Matter — that happens separately, once conversion is confirmed.');
        $this->modalSubmitActionLabel('Accept');
        $this->requiresConfirmation();

        $this->visible(function (MarketplaceIntake $record): bool {
            if (! in_array($record->status, [MarketplaceIntakeStatus::Submitted, MarketplaceIntakeStatus::UnderReview], true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role);
        });

        $this->action(function (MarketplaceIntake $record, MarketplaceIntakeService $intakes): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $firmUser, $intakes): void {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        $intakes->markAccepted($firm, $fresh, $firmUser);
                    },
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not accept prospect')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Prospect accepted')->success()->send();
        });
    }
}
