<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConflictResource\Pages;

use App\Filament\Resources\ConflictResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewConflict — a custom Resource page mirroring ViewSyncFailure's
 * exact shape (see that class's own docblock for the full
 * composite-route/TOCTOU reasoning). No mutating action of any kind is
 * registered anywhere on this page (see ConflictResource's own
 * docblock) — this is a pure, read-only detail view.
 */
class ViewConflict extends Page
{
    protected static string $resource = ConflictResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Conflict';

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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findConflict($admin, $firm, $this->id);
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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findConflict($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This conflict could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Text::make('Monitoring only — resolution happens exclusively through the normal FirmUser dual-approval workflow, never from this console.')
                ->color('gray'),
            Section::make('Conflict')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Provider: '.($row['provider_display_name'] ?? '—')),
                    Text::make('Conflict type: '.$row['conflict_type']),
                    Text::make('Resource type: '.$row['resource_type']),
                    Text::make('Involved entity: '.($row['involved_entity'] ?: '—')),
                    Text::make('Status: '.Str::headline($row['status'] ?? '—')),
                    Text::make('Requires manual review: '.($row['requires_manual_review'] ? 'Yes' : 'No')),
                    Text::make('Detected at: '.($row['detected_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Resolved at: '.($row['resolved_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Expires at: '.($row['expires_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
