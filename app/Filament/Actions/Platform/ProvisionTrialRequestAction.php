<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\TrialRequestStatus;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\TrialRequest;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TrialRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ProvisionTrialRequestAction — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Routes
 * exclusively through TrialRequestService::provision(TrialRequest,
 * Organization, ?actor), the sole writer of a TrialRequest's
 * organization_id/Provisioned transition.
 *
 * Scoping decision (see this pass's own dispatch instructions, which
 * left this discretionary): provision() requires a real Organization to
 * attach — this action DOES surface it, via a proper searchable Select
 * sourced from existing Organization rows (Organization carries no RLS
 * — same Global shape as every other model in this domain — so an
 * ordinary ->pluck('name', 'id') options query is correct and cheap),
 * never a free-text organization id field. This was judged NOT
 * "genuinely awkward from an admin console": choosing an existing
 * organization from a dropdown is an ordinary, well-understood Filament
 * pattern (the exact same shape SelectFilter::make('plan_id') already
 * uses elsewhere in this phase), so scoping Provision out entirely
 * would have been an unnecessary omission rather than a genuine safety
 * concern.
 *
 * TOCTOU-safe, matching every other Phase 3 action's shape. Visible
 * only for a trial request still in the Requested state — provisioning
 * an already-provisioned (or further along) trial would just
 * overwrite its organization_id, which is not this action's intended
 * use.
 */
class ProvisionTrialRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'provisionTrialRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Provision');
        $this->icon(Heroicon::OutlinedBuildingOffice2);
        $this->color('primary');

        $this->schema([
            Select::make('organization_id')
                ->label('Organization')
                ->searchable()
                ->required()
                ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all()),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Provision trial');
        $this->modalDescription('Attaches this trial request to the chosen organization and moves it to Provisioned.');

        $this->visible(fn (TrialRequest $record): bool => $record->status === TrialRequestStatus::Requested);

        $this->action(function (TrialRequest $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, TrialRequestService $trialRequestService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManagePlatformBilling($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $target = TrialRequest::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That trial request could not be found.')->danger()->send();

                return;
            }

            $organization = Organization::query()->find($data['organization_id'] ?? null);

            if ($organization === null) {
                Notification::make()->title('That organization could not be found.')->danger()->send();

                return;
            }

            $trialRequestService->provision($target, $organization, $actor);

            Notification::make()->title('Trial provisioned')->success()->send();
        });
    }
}
