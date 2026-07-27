<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportBatchResource\Pages;

use App\Filament\Resources\ImportBatchResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformDataExportGovernanceDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewImportBatch — composite firmUuid+id route, read-only.
 */
class ViewImportBatch extends Page
{
    protected static string $resource = ImportBatchResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Import Batch';

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
            $row = app(PlatformDataExportGovernanceDirectoryService::class)->findImportBatch($admin, $firm, $this->id);
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
            $row = app(PlatformDataExportGovernanceDirectoryService::class)->findImportBatch($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This import batch could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Import Batch')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Entity type: '.Str::headline((string) $row['entity_type'])),
                    Text::make('Source: '.Str::headline((string) $row['source_type'])),
                    Text::make('Status: '.Str::headline((string) $row['status'])),
                    Text::make('Staged at: '.($row['staged_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Previewed at: '.($row['previewed_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Confirmed at: '.($row['confirmed_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Applied at: '.($row['applied_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Rolled back at: '.($row['rolled_back_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Cancelled at: '.($row['cancelled_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
