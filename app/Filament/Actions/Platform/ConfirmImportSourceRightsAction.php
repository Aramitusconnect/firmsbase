<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Services\MarketplaceImportApplyService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ConfirmImportSourceRightsAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11, section 27. A real, explicit self-attestation
 * step — the importing admin is confirming they have the rights to
 * use this data source, not a rubber stamp inferred from anything
 * else. Required before ApplyImportBatchAction can run.
 */
class ConfirmImportSourceRightsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirmImportSourceRights';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Confirm Source Rights');
        $this->icon(Heroicon::OutlinedShieldCheck);
        $this->color('warning');
        $this->requiresConfirmation();
        $this->modalDescription('I confirm this batch\'s data source is approved for use in the MyAttorney directory (section 27 — no uncontrolled scraping).');

        $this->visible(fn (DirectoryImportBatch $record): bool => ! $record->source_rights_confirmed);

        $this->action(function (DirectoryImportBatch $record, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceImportApplyService $apply): void {
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

            $target = DirectoryImportBatch::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That batch could not be found.')->danger()->send();

                return;
            }

            $apply->confirmSourceRights($target);
            Notification::make()->title('Source rights confirmed')->success()->send();
        });
    }
}
