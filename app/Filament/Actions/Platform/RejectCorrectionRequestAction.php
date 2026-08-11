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

class RejectCorrectionRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'rejectCorrectionRequest';
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
                $corrections->reject($target, $actor, $data['reason']);
                Notification::make()->title('Correction request rejected')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not reject request')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
