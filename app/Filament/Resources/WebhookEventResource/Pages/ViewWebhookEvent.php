<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebhookEventResource\Pages;

use App\Filament\Resources\WebhookEventResource;
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
 * ViewWebhookEvent — a custom Resource page mirroring ViewSyncFailure's
 * exact shape (see that class's own docblock for the full
 * composite-route/TOCTOU reasoning). Never renders
 * `payload_reference_json`/`payload_hash` — those columns are never even
 * selected by
 * PlatformIntegrationCrossFirmDirectoryService::findWebhookEvent() in
 * the first place (see that method's own column allowlist).
 */
class ViewWebhookEvent extends Page
{
    protected static string $resource = WebhookEventResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Webhook Event';

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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findWebhookEvent($admin, $firm, $this->id);
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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findWebhookEvent($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This webhook event could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Webhook Event')
                ->columns(2)
                ->description('The raw webhook payload is never displayed here — only sanitized, allowlisted metadata.')
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Provider: '.$row['provider_key']),
                    Text::make('Event type: '.($row['event_type'] ?? '—')),
                    Text::make('Connection: '.($row['connection_label'] ?? '—')),
                    Text::make('Status: '.Str::headline($row['status'] ?? '—')),
                    Text::make('Processing attempts: '.$row['processing_attempts']),
                    Text::make('Received at: '.($row['received_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Processed at: '.($row['processed_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
