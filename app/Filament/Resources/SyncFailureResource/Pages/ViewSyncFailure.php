<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncFailureResource\Pages;

use App\Filament\Resources\SyncFailureResource;
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
 * ViewSyncFailure — a custom Resource page, NOT the standard Filament
 * ViewRecord (`{record}` route-model-binding), mirroring
 * App\Filament\Resources\FirmUserResource\Pages\ViewFirmUser's exact
 * shape and reasoning: an IntegrationSyncItem row cannot be resolved by
 * a bare id alone under integration_sync_items' FORCE RLS without the
 * correct firm's tenant context already active, so the route carries
 * both `firmUuid` and `id`.
 *
 * Scalar-property-only, TOCTOU-consistent with that same precedent: the
 * only public properties are the two route-parameter values; the row is
 * re-resolved fresh via
 * PlatformIntegrationCrossFirmDirectoryService::findSyncFailure() inside
 * content(), never cached beyond mount()'s own one-time 403/404 check.
 */
class ViewSyncFailure extends Page
{
    protected static string $resource = SyncFailureResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Sync Failure';

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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findSyncFailure($admin, $firm, $this->id);
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
            $row = app(PlatformIntegrationCrossFirmDirectoryService::class)->findSyncFailure($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This sync failure could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Sync Failure')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Provider: '.($row['provider_display_name'] ?? '—')),
                    Text::make('Connection: '.($row['connection_label'] ?? '—')),
                    Text::make('Entity type: '.$row['entity_type']),
                    Text::make('Status: '.Str::headline($row['status'] ?? '—')),
                    Text::make('Failure reason: '.($row['failure_category'] ?? '—')),
                    Text::make('Attempts: '.$row['attempt_count']),
                    Text::make('Retries: '.$row['requeue_count']),
                    Text::make('First seen: '.($row['first_seen_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Last attempt: '.($row['last_attempt_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Next attempt: '.($row['next_attempt_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Terminal at: '.($row['terminal_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
