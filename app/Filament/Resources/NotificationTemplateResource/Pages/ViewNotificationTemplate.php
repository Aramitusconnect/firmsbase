<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformNotificationTemplateDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewNotificationTemplate — a custom Resource page mirroring
 * ViewConflict's shape, with one difference: the route's `firmUuid`
 * segment is the literal string `global` for a global-default template
 * (firm_id null) rather than a real firm UUID — resolveFirm() below
 * translates that back to a null Firm, matching
 * PlatformNotificationTemplateDirectoryService::list()/find()'s own
 * `?Firm $firm = null` meaning "global, no context" convention.
 */
class ViewNotificationTemplate extends Page
{
    protected static string $resource = NotificationTemplateResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Notification Template';

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

        try {
            $row = app(PlatformNotificationTemplateDirectoryService::class)->find($admin, $this->resolveFirm(), $this->id);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($row === null) {
            abort(404);
        }
    }

    private function resolveFirm(): ?Firm
    {
        return $this->firmUuid === 'global' ? null : Firm::findByUuid($this->firmUuid);
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        try {
            $row = app(PlatformNotificationTemplateDirectoryService::class)->find($admin, $this->resolveFirm(), $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This notification template could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Text::make('Content/metadata management only — no real email transport exists anywhere in this codebase. This page never implies a send/preview-send capability.')
                ->color('gray'),
            Section::make('Notification Template')
                ->columns(2)
                ->schema([
                    Text::make('Key: '.$row['key']),
                    Text::make('Channel: '.Str::headline($row['channel'] ?? '—')),
                    Text::make('Language: '.$row['language']),
                    Text::make('Scope: '.($row['is_global_default'] ? 'Global default' : 'Firm override ('.($row['firm_name'] ?? '—').')')),
                    Text::make('Status: '.Str::headline($row['status'] ?? '—')),
                    Text::make('Subject: '.($row['subject'] ?? '—')),
                    Text::make('From email: '.($row['from_email'] ?? '—')),
                    Text::make('From domain: '.($row['from_domain'] ?? '—')),
                    Text::make('Domain verified at: '.($row['domain_verified_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Last updated: '.($row['updated_at']?->toDayDateTimeString() ?? '—')),
                ]),
            Section::make('Body')
                ->schema([
                    Text::make($row['body']),
                ]),
        ]);
    }
}
