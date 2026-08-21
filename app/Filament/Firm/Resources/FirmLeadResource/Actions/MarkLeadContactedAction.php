<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Actions;

use App\Enums\FirmLeadStatus;
use App\Models\FirmLead;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * MarkLeadContactedAction — Mission 5B (5.6, internal lead lifecycle).
 * Of FirmLeadStatus's 7 cases, only New/Converted were ever actually
 * written anywhere before this mission (confirmed by exhaustive grep);
 * this is the first real writer of Contacted. Deliberately as narrow
 * as ConvertLeadToClientAction/CompleteDeadlineAction/
 * CancelDeadlineAction: a plain `$lead->update(['status' => ...])`
 * inside runWithFirmContext(), no side effects, no new service class.
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

                    if ($fresh->status !== FirmLeadStatus::New) {
                        Notification::make()->title('This lead is no longer New')->danger()->send();

                        return;
                    }

                    $fresh->update(['status' => FirmLeadStatus::Contacted]);
                    Notification::make()->title('Lead marked as Contacted')->success()->send();
                },
            );
        });
    }
}
