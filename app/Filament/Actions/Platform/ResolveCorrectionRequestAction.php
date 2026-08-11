<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\CorrectionState;
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
 * ResolveCorrectionRequestAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Only from Approved. Deliberately no field-
 * change inputs here (checkpoint 8's own resolve() supports them, but
 * this Admin surface keeps "the actual fix" as a separate, explicit
 * DirectoryFirmResource edit followed by resolving the request to
 * close it out — avoids a large dynamic-field modal this checkpoint).
 */
class ResolveCorrectionRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resolveCorrectionRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve');
        $this->icon(Heroicon::OutlinedCheckBadge);
        $this->color('success');
        $this->schema([
            Textarea::make('resolution_notes')->label('Resolution notes')->required()->rows(2),
        ]);
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryCorrectionRequest $record): bool => $record->state === CorrectionState::Approved);

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
                $corrections->resolve($target, $actor, $data['resolution_notes']);
                Notification::make()->title('Correction request resolved')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not resolve request')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
