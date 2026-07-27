<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformTimelineEventDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewAuditLog — a custom Resource page mirroring
 * DeadLetterQueueResource's ViewDeadLetterQueueEvent shape (composite
 * firmUuid+id route, TOCTOU-safe re-fetch on every render). metadata_json
 * is rendered as pretty-printed JSON — see
 * PlatformTimelineEventDirectoryService's own docblock for the redaction
 * review backing that choice (IDs/enum values/short classification
 * strings only, across every current TimelineEventRecorder call site).
 */
class ViewAuditLog extends Page
{
    protected static string $resource = AuditLogResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Audit Log Event';

    public string $firmUuid = '';

    public int $id = 0;

    public function mount(string $firmUuid, int $id): void
    {
        $this->firmUuid = $firmUuid;
        $this->id = $id;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $row = app(PlatformTimelineEventDirectoryService::class)->find($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($row === null) {
            abort(404);
        }
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $row = app(PlatformTimelineEventDirectoryService::class)->find($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This audit log event could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Audit Log Event')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Event type: '.$row['event_type']),
                    Text::make('Subject: '.($row['subject_type'] !== null ? $row['subject_type'].' #'.$row['subject_id'] : '—')),
                    Text::make('Actor: '.($row['actor_type'] !== null ? $row['actor_type'].' #'.$row['actor_id'] : '—')),
                    Text::make('Occurred at: '.($row['occurred_at']?->toDayDateTimeString() ?? '—')),
                ]),
            Section::make('Metadata')
                ->description('Read-only. TimelineEventRecorder is write-only — no admin action here can alter this event.')
                ->schema([
                    Text::make(empty($row['metadata_json']) ? '—' : json_encode($row['metadata_json'], JSON_PRETTY_PRINT)),
                ]),
        ]);
    }
}
