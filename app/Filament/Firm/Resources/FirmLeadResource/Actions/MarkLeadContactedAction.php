<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Actions;

use App\Enums\FirmLeadStatus;
use App\Models\FirmLead;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\FirmLeadWorkflowService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * MarkLeadContactedAction — Mission 5B (5.6, internal lead lifecycle).
 * Of FirmLeadStatus's 7 cases, only New/Converted were ever actually
 * written anywhere before this mission (confirmed by exhaustive grep);
 * this is the first real writer of Contacted. The actual status write
 * lives in FirmLeadWorkflowService::markContacted() (Governance
 * Section 25+ WorkflowTransitionEnforcementSearchTest requires every
 * direct write of a catalog workflow status enum to live in
 * app/Services) — this action's own job is auth/tenant-context/
 * notification only.
 *
 * Only ever fires from New — a lead that has already moved further
 * along (Contacted or beyond) has nothing left for this specific
 * action to do; ScheduleConsultationAction/MarkLeadLostAction cover
 * the next transitions. Reuses
 * ClientCrmAccessPolicyService::canManageLead() unchanged — the exact
 * same role ceiling FirmLeadResource's own Create/Edit form already
 * requires, no new policy method needed.
 */
class MarkLeadContactedAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'markLeadContacted';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Contacted');
        $this->icon(Heroicon::OutlinedPhone);
        $this->color('info');
        $this->requiresConfirmation();

        $this->visible(function (FirmLead $record): bool {
            if ($record->status !== FirmLeadStatus::New) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role);
        });

        $this->action(function (FirmLead $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = FirmLead::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this lead.')->danger()->send();

                        return;
                    }

                    try {
                        app(FirmLeadWorkflowService::class)->markContacted($fresh);
                        Notification::make()->title('Lead marked as Contacted')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('This lead is no longer New')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
