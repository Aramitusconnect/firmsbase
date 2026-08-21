<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CalendarEventResource\Pages;

use App\Filament\Firm\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\CalendarEventService;
use App\Services\TaskCrudAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateCalendarEvent — the ONLY UI path that may create a
 * CalendarEvent row; calls `CalendarEventService::createStandalone()`
 * directly (finally giving that method its first real caller — see
 * that service's own docblock, which notes it "has no production
 * caller today"), never a bare `CalendarEvent::create()`. Mirrors
 * CreateDeadline's tenant-context wrap and re-check discipline exactly.
 *
 * Gated on `TaskCrudAccessPolicyService::canManageTask()` — the same
 * front-desk-inclusive ceiling CalendarEventResource's own docblock
 * explains (a standalone calendar entry, e.g. "client meeting", is
 * routine scheduling work, not a legal deadline).
 */
class CreateCalendarEvent extends CreateRecord
{
    protected static string $resource = CalendarEventResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);
        abort_unless(app(TaskCrudAccessPolicyService::class)->canManageTask($firmUser->role), 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): CalendarEvent {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                    ? Matter::query()->where('id', $data['matter_id'])->first()
                    : null;

                return app(CalendarEventService::class)->createStandalone(
                    firm: $firm,
                    title: $data['title'],
                    startsAt: Carbon::parse($data['starts_at']),
                    endsAt: isset($data['ends_at']) && $data['ends_at'] !== null ? Carbon::parse($data['ends_at']) : null,
                    matter: $matter,
                    allDay: (bool) ($data['all_day'] ?? false),
                    createdBy: $firmUser->user,
                );
            },
        );
    }
}
