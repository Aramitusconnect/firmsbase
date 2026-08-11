<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

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
 * RejectDirectoryClaimAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Not step-up gated — "reject" is not in the
 * mission spec's own named high-risk list (unlike approve/revoke),
 * and rejecting never links/mutates the underlying directory listing.
 */
class RejectDirectoryClaimAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'rejectDirectoryClaim';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reject');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ]);
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryClaim $record): bool => $record->state->isActive());

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
                $claims->reject($target, $actor, $data['reason']);
                Notification::make()->title('Claim rejected')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not reject claim')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
