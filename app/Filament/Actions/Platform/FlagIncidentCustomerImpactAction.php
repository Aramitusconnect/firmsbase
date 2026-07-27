<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * FlagIncidentCustomerImpactAction — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Header action on
 * ViewPlatformIncident. Routes exclusively through
 * IncidentService::flagCustomerImpact(). Pre-fills the form with the
 * incident's current value so this reads as a toggle, not a bare
 * confirmation with no visible current state.
 */
class FlagIncidentCustomerImpactAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'flagIncidentCustomerImpact';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Flag Customer Impact');
        $this->icon(Heroicon::OutlinedUserGroup);
        $this->color('gray');

        $this->schema([
            Checkbox::make('customer_impact')->label('Customer impact'),
        ]);

        $this->fillForm(fn (IncidentEvent $record): array => ['customer_impact' => $record->customer_impact]);

        $this->requiresConfirmation();
        $this->modalHeading('Update customer impact flag');

        $this->action(function (IncidentEvent $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, IncidentService $incidentService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageOperations($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $incidentService->flagCustomerImpact(
                null,
                $record->correlation_id,
                (bool) ($data['customer_impact'] ?? false),
                null,
                $actor,
            );

            Notification::make()->title('Customer impact flag updated')->success()->send();
        });
    }
}
