<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeadLetterQueueResource\Pages;

use App\Filament\Resources\DeadLetterQueueResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewDeadLetterQueueEvent — a custom Resource page mirroring
 * ViewSyncFailure's exact shape (see that class's own docblock for the
 * full composite-route/TOCTOU reasoning).
 */
class ViewDeadLetterQueueEvent extends Page
{
    protected static string $resource = DeadLetterQueueResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Dead-Lettered Event';

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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findDeadLetterEvent($admin, $firm, $this->id);
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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findDeadLetterEvent($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This dead-lettered event could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Dead-Lettered Event')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Provider: '.($row['provider_display_name'] ?? '—')),
                    Text::make('Connection: '.($row['connection_label'] ?? '—')),
                    Text::make('Original event type: '.$row['original_event_type']),
                    Text::make('Failure reason: '.($row['failure_category'] ?? '—')),
                    Text::make('Dead-lettered at: '.($row['dead_lettered_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Retry count: '.$row['requeue_count'].' / '.$row['max_requeues']),
                    Text::make('Last requeued at: '.($row['requeued_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
