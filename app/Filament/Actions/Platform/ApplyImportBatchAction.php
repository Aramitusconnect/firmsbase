<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Services\MarketplaceImportApplyService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ApplyImportBatchAction — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 11. Not individually named in the mission's own high-risk
 * list, but a bulk write potentially affecting many public listings —
 * step-up gated as a defensible extension of that list's own spirit.
 * MarketplaceImportApplyService::apply() itself refuses to run without
 * source_rights_confirmed (section 27) regardless of this gate.
 *
 * Deliberately applies every eligible row with default decisions
 * (create new, skip every Duplicate row) — no per-row decision UI in
 * this checkpoint; a Duplicate row always requires a separate,
 * explicit per-row "update" decision not exposed here, so this action
 * can never silently overwrite an existing listing in bulk.
 */
class ApplyImportBatchAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'applyImportBatch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Apply');
        $this->icon(Heroicon::OutlinedPlayCircle);
        $this->color('success');
        StepUpAuthentication::protect($this, 'platform_admin');
        $this->modalDescription('Creates a new Draft listing for every Valid, non-duplicate row. Every Duplicate row is skipped (never silently merged) — review duplicates individually before a future targeted update.');

        $this->visible(fn (DirectoryImportBatch $record): bool => in_array($record->status, [DirectoryImportBatchStatus::Validated, DirectoryImportBatchStatus::Previewed, DirectoryImportBatchStatus::SourceApprovalRequired], true));

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

            try {
                $applied = $apply->apply($target, $actor);
                Notification::make()->title("Batch applied — {$applied->applied_rows} created, {$applied->skipped_rows} skipped.")->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not apply batch')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
