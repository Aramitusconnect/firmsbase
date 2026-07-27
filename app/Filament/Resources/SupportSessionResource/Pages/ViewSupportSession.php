<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSessionResource\Pages;

use App\Filament\Resources\SupportSessionResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformSupportAccessDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewSupportSession — a custom Resource page mirroring ViewConflict's
 * exact shape (composite firmUuid/id route, TOCTOU-safe fresh read on
 * every render).
 */
class ViewSupportSession extends Page
{
    protected static string $resource = SupportSessionResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Approved Support Session';

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
            $row = app(PlatformSupportAccessDirectoryService::class)->findApprovedSupportSession($admin, $firm, $this->id);
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
            $row = app(PlatformSupportAccessDirectoryService::class)->findApprovedSupportSession($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This approved support session could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Approved Support Session')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Platform admin: '.($row['platform_admin_name'] ?? '—')),
                    Text::make('Status: '.Str::headline($row['status'] ?? '—')),
                    Text::make('Support access request id: '.$row['support_access_request_id']),
                    Text::make('Started at: '.($row['started_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Expires at: '.($row['expires_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Ended at: '.($row['ended_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Revoked at: '.($row['revoked_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
