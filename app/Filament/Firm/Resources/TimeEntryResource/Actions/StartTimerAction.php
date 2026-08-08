<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Actions;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TimeTrackingSession;
use App\Services\TenantContextService;
use App\Services\TimeExpenseAccessPolicyService;
use App\Services\TimeTrackingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * StartTimerAction — a header action wired directly to
 * TimeTrackingService::start(), a REAL, durably-persisted backend timer
 * (see TimeEntryResource's own docblock for why this is not a
 * client-side simulation). Refuses to start a second timer for the
 * same user while one is already Active — the check runs inside the
 * same runWithFirmContext() wrap as the mutation itself (TOCTOU
 * discipline matching every other Action in this panel).
 *
 * Deliberately always starts the timer for the ACTING user themselves
 * — there is no "log time for another user" field, matching this
 * cluster's "log my own billable work" role-ceiling reasoning (see
 * TimeExpenseAccessPolicyService's own docblock). Stopping the timer
 * (StopTimerAction) creates exactly one Draft TimeEntry owned by that
 * same user.
 */
class StartTimerAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'startTimer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Start Timer');
        $this->icon(Heroicon::OutlinedPlay);
        $this->color('success');

        $this->schema([
            Select::make('matter_id')
                ->label('Matter')
                ->options(fn (): array => Matter::query()
                    ->with('client')
                    ->get()
                    ->mapWithKeys(fn (Matter $matter): array => [
                        $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                    ])
                    ->all())
                ->searchable()
                ->nullable(),
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                ->searchable()
                ->nullable(),
            Toggle::make('is_billable')->label('Billable')->default(true),
            Textarea::make('description')->rows(2),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(TimeExpenseAccessPolicyService::class)->canManageTimeEntry($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canManageTimeEntry($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($data, $firmUser): void {
                    $alreadyActive = TimeTrackingSession::query()
                        ->where('user_id', $firmUser->user_id)
                        ->where('status', TimeTrackingSessionStatus::Active)
                        ->exists();

                    if ($alreadyActive) {
                        Notification::make()
                            ->title('A timer is already running')
                            ->body('Stop your current timer before starting a new one.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $firm = Firm::query()->findOrFail($firmUser->firm_id);
                    $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                        ? Matter::query()->where('id', $data['matter_id'])->first()
                        : null;
                    $client = isset($data['client_id']) && $data['client_id'] !== null
                        ? Client::query()->where('id', $data['client_id'])->first()
                        : null;

                    app(TimeTrackingService::class)->start(
                        firm: $firm,
                        user: $firmUser->user,
                        matter: $matter,
                        client: $client,
                        isBillable: (bool) ($data['is_billable'] ?? true),
                        description: $data['description'] ?? null,
                    );

                    Notification::make()->title('Timer started')->success()->send();
                },
            );
        });
    }
}
