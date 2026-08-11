<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Services\MarketplaceCorrectionService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ApproveCorrectionRequestAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Not in the mission spec's own named high-risk
 * list — approve() only records that a report is valid, it never
 * mutates the listing itself (that happens only in resolve()).
 */
class ApproveCorrectionRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveCorrectionRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->schema([
            Textarea::make('reviewer_notes')->label('Reviewer notes (internal)')->rows(2)->nullable(),
        ]);
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryCorrectionRequest $record): bool => $record->state->isActive());

        $this->action(function (DirectoryCorrectionRequest $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceCorrectionService $corrections): void {
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
                $corrections->approve($target, $actor, $data['reviewer_notes'] ?? null);
                Notification::make()->title('Correction request approved')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not approve request')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
