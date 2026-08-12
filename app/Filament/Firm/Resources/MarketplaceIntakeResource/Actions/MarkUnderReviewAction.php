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
 * MarkUnderReviewAction — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 9. Submitted -> UnderReview only. TOCTOU +
 * tenant-context discipline matches every other Action in this panel
 * (see ConvertLeadToClientAction's own docblock) — re-fetches the
 * intake fresh by primary key inside runWithFirmContext(), never
 * trusts the page-load-time $record.
 */
class MarkUnderReviewAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'markUnderReview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Under Review');
        $this->icon(Heroicon::OutlinedEye);
        $this->color('primary');
        $this->requiresConfirmation();

        $this->visible(function (MarketplaceIntake $record): bool {
            if ($record->status !== MarketplaceIntakeStatus::Submitted) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role);
        });

        $this->action(function (MarketplaceIntake $record, MarketplaceIntakeService $intakes): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $firmUser, $intakes): void {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        $intakes->markUnderReview($firm, $fresh, $firmUser);
                    },
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not mark under review')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Marked under review')->success()->send();
        });
    }
}
