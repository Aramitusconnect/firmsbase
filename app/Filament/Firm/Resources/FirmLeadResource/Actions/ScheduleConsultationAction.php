<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Actions;

use App\Enums\FirmLeadStatus;
use App\Models\Consultation;
use App\Models\FirmLead;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ScheduleConsultationAction — Mission 5B (5.6 + 5.7 built coherently
 * together). The first real writer of
 * FirmLeadStatus::ConsultationScheduled. Unlike
 * MarkLeadContactedAction/MarkLeadLostAction, this one carries a real
 * side effect beyond the status write: it also creates the
 * Consultation row this scheduled meeting represents
 * (FirmLead::consultations(), a real, already-defined HasMany) —
 * without it, moving a lead to ConsultationScheduled would be a status
 * label with nothing behind it. ConsultationsRelationManager (on
 * ViewFirmLead) is where a firm manages consultations after the fact
 * (reschedule, mark held, record an outcome); this action only covers
 * the quick "log a consultation and advance the lead" step from the
 * lead's own row.
 *
 * Available from New or Contacted only (the lead has not yet had a
 * consultation scheduled). Never available once Converted/Lost/
 * Archived, or once already ConsultationScheduled/ConsultationHeld —
 * scheduling an ADDITIONAL consultation for a lead already past this
 * step belongs to ConsultationsRelationManager, not this quick action.
 * Reuses ClientCrmAccessPolicyService::canManageLead() unchanged, same
 * ceiling as MarkLeadContactedAction/MarkLeadLostAction.
 */
class ScheduleConsultationAction extends Action
{
    private const SCHEDULABLE_STATUSES = [
        FirmLeadStatus::New,
        FirmLeadStatus::Contacted,
    ];

    public static function getDefaultName(): ?string
    {
        return 'scheduleConsultation';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Schedule Consultation');
        $this->icon(Heroicon::OutlinedCalendarDays);
        $this->color('warning');
        $this->modalHeading('Schedule Consultation');
        $this->modalSubmitActionLabel('Schedule');

        $this->schema([
            DateTimePicker::make('scheduled_at')
                ->label('Scheduled At')
                ->native(false)
                ->required()
                ->default(now()->addDay()),
            Textarea::make('notes')->rows(3)->nullable(),
        ]);

        $this->visible(function (FirmLead $record): bool {
            if (! in_array($record->status, self::SCHEDULABLE_STATUSES, true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role);
        });

        $this->action(function (array $data, FirmLead $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $data, $firmUser): void {
                    $fresh = FirmLead::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this lead.')->danger()->send();

                        return;
                    }

                    if (! in_array($fresh->status, self::SCHEDULABLE_STATUSES, true)) {
                        Notification::make()->title('This lead can no longer have a consultation scheduled this way')->danger()->send();

                        return;
                    }

                    Consultation::create([
                        'firm_id' => $fresh->firm_id,
                        'firm_lead_id' => $fresh->id,
                        'scheduled_at' => $data['scheduled_at'],
                        'notes' => $data['notes'] ?? null,
                    ]);

                    $fresh->update(['status' => FirmLeadStatus::ConsultationScheduled]);
                    Notification::make()->title('Consultation scheduled')->success()->send();
                },
            );
        });
    }
}
