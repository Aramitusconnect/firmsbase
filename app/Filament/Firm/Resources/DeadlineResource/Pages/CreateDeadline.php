<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DeadlineResource\Pages;

use App\Filament\Firm\Resources\DeadlineResource;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\DeadlineService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateDeadline — the ONLY UI path that may create a Deadline row;
 * calls DeadlineService::create() directly, NEVER a bare
 * `Deadline::create()` (this mission's explicit rule). This matters
 * more here than for CreateTask: DeadlineService::create()
 * auto-creates a matching CalendarEvent in the same transaction (Firm
 * Feature Manifest §3 / that service's own docblock) — a bare model
 * create would silently skip that and leave the deadline with no
 * calendar representation at all.
 *
 * Tenant-context wrap matches every other create page in this panel —
 * DeadlineService's own internal `runWithFirmContext()` call is safe/
 * re-entrant nested inside this one.
 */
class CreateDeadline extends CreateRecord
{
    protected static string $resource = DeadlineResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): Deadline {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                    ? Matter::query()->where('id', $data['matter_id'])->first()
                    : null;

                $reminderOffsets = isset($data['reminder_offsets_days']) && $data['reminder_offsets_days'] !== []
                    ? array_map('intval', $data['reminder_offsets_days'])
                    : null;

                return app(DeadlineService::class)->create(
                    firm: $firm,
                    title: $data['title'],
                    deadlineType: $data['deadline_type'],
                    dueAt: Carbon::parse($data['due_at']),
                    matter: $matter,
                    jurisdiction: $data['jurisdiction'] ?? null,
                    source: $data['source'] ?? null,
                    reminderOffsetsDays: $reminderOffsets,
                    createdBy: $firmUser->user,
                );
            },
        );
    }
}
