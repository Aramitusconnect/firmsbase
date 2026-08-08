<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Pages;

use App\Filament\Firm\Resources\TimeEntryResource;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Services\TenantContextService;
use App\Services\TimeEntryApprovalService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateTimeEntry — the ONLY UI path that may create a manual (non-
 * timer) TimeEntry row; calls
 * TimeEntryApprovalService::createManualEntry() directly, NEVER a bare
 * `TimeEntry::create()` (this mission's explicit rule — mirrors
 * CreateTask/CreateDeadline's exact discipline). Always creates the
 * entry for the ACTING user — see TimeEntryResource's own docblock for
 * why no "log time for another user" field exists.
 *
 * The form's Hours/Minutes fields are combined into a single
 * whole-second integer here (TimeEntry's own model docblock: "seconds
 * is a whole-second integer column").
 *
 * Tenant-context wrap matches every other create page in this panel —
 * TimeEntryApprovalService's own internal runWithFirmContext() call is
 * safe/re-entrant nested inside this one.
 */
class CreateTimeEntry extends CreateRecord
{
    protected static string $resource = TimeEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): TimeEntry {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                    ? Matter::query()->where('id', $data['matter_id'])->first()
                    : null;
                $client = isset($data['client_id']) && $data['client_id'] !== null
                    ? Client::query()->where('id', $data['client_id'])->first()
                    : null;

                $seconds = ((int) ($data['hours'] ?? 0)) * 3600 + ((int) ($data['minutes'] ?? 0)) * 60;

                return app(TimeEntryApprovalService::class)->createManualEntry(
                    firm: $firm,
                    user: $firmUser->user,
                    seconds: $seconds,
                    workedOn: Carbon::parse($data['worked_on']),
                    matter: $matter,
                    client: $client,
                    isBillable: (bool) ($data['is_billable'] ?? true),
                    description: $data['description'] ?? null,
                );
            },
        );
    }
}
