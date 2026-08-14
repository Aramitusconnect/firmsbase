<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Services\MarketplaceCorrectionService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * MarkCorrectionUnderReviewAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT5). Same "previously-built, never-
 * wired" situation as the Claims workspace's own MarkClaimUnderReviewAction
 * — MarketplaceCorrectionService::markUnderReview() has existed since
 * Mission 2 checkpoint 11 and was never reachable from the UI.
 */
class MarkCorrectionUnderReviewAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'markCorrectionUnderReview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Under Review');
        $this->icon(Heroicon::OutlinedMagnifyingGlass);
        $this->color('gray');
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryCorrectionRequest $record): bool => $record->state === CorrectionState::Pending);

        $this->action(function (DirectoryCorrectionRequest $record, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceCorrectionService $corrections): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $decision = $accessPolicy->canManageMarketplaceGovernance($actor);
            if (! $decision->allowed) {
                Notification::make()->title('Not permitted')->body($decision->reason)->danger()->send();

                return;
            }

            $target = DirectoryCorrectionRequest::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That request could not be found.')->danger()->send();

                return;
            }

            try {
                $corrections->markUnderReview($target, $actor);
                Notification::make()->title('Request marked under review')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not update request')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
