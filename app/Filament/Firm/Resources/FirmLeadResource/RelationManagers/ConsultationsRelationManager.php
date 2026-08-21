<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\RelationManagers;

use App\Enums\FirmLeadStatus;
use App\Models\Consultation;
use App\Models\ConsultationOutcome;
use App\Models\FirmLead;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ConsultationsRelationManager — Mission 5B (5.7). "Consultations" tab
 * on FirmLeadResource\ViewFirmLead, listing `Consultation` rows via
 * `FirmLead::consultations()` (a real, already-defined HasMany — same
 * "no manual getRelationship() override needed" shape ContactsRelation
 * Manager/ExpensesRelationManager already use for their own already-
 * defined relations).
 *
 * Unlike every other RelationManager in this panel (all deliberately
 * read-only cross-reference tabs — see ExpensesRelationManager/
 * ContactsRelationManager's own docblocks), this one carries real
 * mutation: `Consultation`/`ConsultationOutcome` had zero Filament
 * references anywhere before this mission, so this tab IS the only
 * management surface for consultations. Both mutating actions follow
 * this panel's established Action-in-RelationManager shape (mirrors
 * TriggerManualSyncAction on SyncRunsRelationManager): re-fetch fresh
 * inside runWithFirmContext(), re-check the role ceiling there, never
 * a bare Eloquent update outside that wrap.
 *
 * "Schedule Consultation" (header action) creates an additional
 * Consultation for a lead that already has one scheduled/held — the
 * quick "log a consultation and advance the lead" step for a NEW lead
 * still lives on FirmLeadResource's own ScheduleConsultationAction row
 * action; this tab is where a firm manages the full consultation
 * history, including a second/rescheduled consultation.
 *
 * "Mark Held" (record action) is the first real writer of
 * FirmLeadStatus::ConsultationHeld: recording that a specific
 * consultation actually happened also advances the parent lead's own
 * status, unless the lead is already at a terminal status
 * (Converted/Lost/Archived) — never regresses a lead that has already
 * moved past this point.
 *
 * ConsultationOutcome is a small, firm-scoped catalog (mirrors
 * LeadSource) — this tab lets a consultation reference whatever
 * outcomes already exist for the firm; it deliberately does not build
 * any outcome-catalog create/edit UI (out of this mission's scope, per
 * this mission's own instruction not to over-build that).
 */
class ConsultationsRelationManager extends RelationManager
{
    protected static string $relationship = 'consultations';

    protected static ?string $title = 'Consultations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof FirmLead || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(ClientCrmAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduled_at')->label('Scheduled')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('held_at')->label('Held')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('outcome.name')->label('Outcome')->placeholder('—'),
                TextColumn::make('notes')->limit(60)->placeholder('—'),
                IconColumn::make('converted')->boolean(),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->headerActions([
                $this->scheduleConsultationAction(),
            ])
            ->recordActions([
                $this->markHeldAction(),
            ])
            ->toolbarActions([]);
    }

    private function scheduleConsultationAction(): Action
    {
        return Action::make('addConsultation')
            ->label('Schedule Consultation')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->schema([
                DateTimePicker::make('scheduled_at')
                    ->label('Scheduled At')
                    ->native(false)
                    ->required()
                    ->default(now()->addDay()),
                Textarea::make('notes')->rows(3)->nullable(),
            ])
            ->visible(function (RelationManager $livewire): bool {
                $lead = $livewire->getOwnerRecord();

                if (! $lead instanceof FirmLead || $lead->isConverted()) {
                    return false;
                }

                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || (int) $firmUser->firm_id !== (int) $lead->firm_id) {
                    return false;
                }

                return app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role);
            })
            ->action(function (array $data, RelationManager $livewire): void {
                $ownerRecord = $livewire->getOwnerRecord();
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role)) {
                    Notification::make()->title('Not permitted')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($ownerRecord, $data, $firmUser): void {
                        $lead = FirmLead::query()->where('id', $ownerRecord->getKey())->firstOrFail();

                        if ((int) $firmUser->firm_id !== (int) $lead->firm_id) {
                            Notification::make()->title('You do not have access to this lead.')->danger()->send();

                            return;
                        }

                        if ($lead->isConverted()) {
                            Notification::make()->title('This lead has already been converted')->danger()->send();

                            return;
                        }

                        Consultation::create([
                            'firm_id' => $lead->firm_id,
                            'firm_lead_id' => $lead->id,
                            'scheduled_at' => $data['scheduled_at'],
                            'notes' => $data['notes'] ?? null,
                        ]);

                        if (in_array($lead->status, [FirmLeadStatus::New, FirmLeadStatus::Contacted], true)) {
                            $lead->update(['status' => FirmLeadStatus::ConsultationScheduled]);
                        }

                        Notification::make()->title('Consultation scheduled')->success()->send();
                    },
                );
            });
    }

    private function markHeldAction(): Action
    {
        return Action::make('markConsultationHeld')
            ->label('Mark Held')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                DateTimePicker::make('held_at')->label('Held At')->native(false)->required()->default(now()),
                Select::make('consultation_outcome_id')
                    ->label('Outcome')
                    ->options(fn (RelationManager $livewire): array => ConsultationOutcome::query()
                        ->where('firm_id', $livewire->getOwnerRecord()->firm_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->nullable(),
                Toggle::make('converted')->label('Likely to convert'),
            ])
            ->visible(function (Consultation $record): bool {
                if ($record->held_at !== null) {
                    return false;
                }

                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                    return false;
                }

                return app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role);
            })
            ->action(function (array $data, Consultation $record): void {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canManageLead($firmUser->role)) {
                    Notification::make()->title('Not permitted')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $data, $firmUser): void {
                        $fresh = Consultation::query()->where('id', $record->id)->firstOrFail();

                        if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                            Notification::make()->title('You do not have access to this consultation.')->danger()->send();

                            return;
                        }

                        if ($fresh->held_at !== null) {
                            Notification::make()->title('This consultation was already marked held')->danger()->send();

                            return;
                        }

                        $fresh->update([
                            'held_at' => $data['held_at'],
                            'consultation_outcome_id' => $data['consultation_outcome_id'] ?? null,
                            'converted' => (bool) ($data['converted'] ?? false),
                        ]);

                        $lead = FirmLead::query()->where('id', $fresh->firm_lead_id)->first();

                        if ($lead !== null && ! in_array($lead->status, [FirmLeadStatus::Converted, FirmLeadStatus::Lost, FirmLeadStatus::Archived], true)) {
                            $lead->update(['status' => FirmLeadStatus::ConsultationHeld]);
                        }

                        Notification::make()->title('Consultation marked as held')->success()->send();
                    },
                );
            });
    }
}
