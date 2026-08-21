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
 * MarkLeadLostAction — Mission 5B (5.6, internal lead lifecycle). The
 * first real writer of FirmLeadStatus::Lost (confirmed never written
 * anywhere before this mission). Available from any non-terminal
 * status (New/Contacted/ConsultationScheduled/ConsultationHeld) — a
 * lead can go cold at any stage of intake, not only from New. Never
 * available once a lead is Converted (a real client relationship
 * cannot retroactively become "lost") or already Lost/Archived.
 *
 * The actual status write lives in FirmLeadWorkflowService::markLost()
 * (Governance Section 25+ WorkflowTransitionEnforcementSearchTest
 * requires every direct write of a catalog workflow status enum to
 * live in app/Services), same as MarkLeadContactedAction — no
 * retention or purge behavior lives here (that is explicitly Phase
 * 17's job per FirmLeadStatus's own docblock; this action only records
 * the status).
 * Reuses ClientCrmAccessPolicyService::canManageLead() unchanged.
 */
class MarkLeadLostAction extends Action
{
    private const NON_TERMINAL_STATUSES = [
        FirmLeadStatus::New,
        FirmLeadStatus::Contacted,
        FirmLeadStatus::ConsultationScheduled,
        FirmLeadStatus::ConsultationHeld,
    ];

    public static function getDefaultName(): ?string
    {
        return 'markLeadLost';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Lost');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->visible(function (FirmLead $record): bool {
            if (! in_array($record->status, self::NON_TERMINAL_STATUSES, true)) {
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
                        app(FirmLeadWorkflowService::class)->markLost($fresh);
                        Notification::make()->title('Lead marked as Lost')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('This lead can no longer be marked Lost')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
