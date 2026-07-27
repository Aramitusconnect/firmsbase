<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCaseResource\Pages;

use App\Filament\Resources\SupportCaseResource;
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
 * ViewSupportCase — a custom Resource page mirroring ViewConflict's
 * exact shape (composite firmUuid/id route, TOCTOU-safe fresh read on
 * every render, never trusting a cached mount()-time value).
 */
class ViewSupportCase extends Page
{
    protected static string $resource = SupportCaseResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Support Case';

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
            $row = app(PlatformSupportAccessDirectoryService::class)->findSupportCase($admin, $firm, $this->id);
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
            $row = app(PlatformSupportAccessDirectoryService::class)->findSupportCase($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This support case could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Text::make('Standard-access approval/denial happens on the firm side — no such UI exists yet on either panel (a deliberate architectural boundary, not a gap). This console can view request status and mark stale requests Expired.')
                ->color('gray'),
            Section::make('Support Case')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Requested by: '.($row['requested_by_name'] ?? '—')),
                    Text::make('Access type: '.Str::headline($row['access_type'] ?? '—')),
                    Text::make('Status: '.Str::headline($row['status'] ?? '—')),
                    Text::make('Reason: '.$row['reason']),
                    Text::make('Emergency justification: '.($row['emergency_justification'] ?? '—')),
                    Text::make('Requested duration: '.$row['requested_duration_minutes'].' minutes'),
                    Text::make('Requested at: '.($row['created_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Approved at: '.($row['approved_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Denied at: '.($row['denied_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Last updated: '.($row['updated_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
