<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Services\MarketplaceClaimService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RequireClaimEvidenceAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT4). Same "previously-built,
 * never-wired" situation as MarkClaimUnderReviewAction — this is
 * "Request More Information" from the mission spec, backed by
 * MarketplaceClaimService::requireEvidence() (Mission 2 checkpoint
 * 11), which stores the request as the claim's reviewer_notes.
 */
class RequireClaimEvidenceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'requireClaimEvidence';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request More Information');
        $this->icon(Heroicon::OutlinedQuestionMarkCircle);
        $this->color('warning');
        $this->schema([
            Textarea::make('note')->label('What is needed from the claimant?')->required()->rows(2),
        ]);
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryClaim $record): bool => in_array($record->state, [ClaimState::Pending, ClaimState::UnderReview], true));

        $this->action(function (DirectoryClaim $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceClaimService $claims): void {
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

            $target = DirectoryClaim::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That claim could not be found.')->danger()->send();

                return;
            }

            try {
                $claims->requireEvidence($target, $actor, $data['note']);
                Notification::make()->title('Additional information requested')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not update claim')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
