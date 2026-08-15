<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExportJobResource\Pages;

use App\Filament\Resources\ExportJobResource;
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
 * ViewExportJob — composite firmUuid+id route, read-only, mirroring
 * DeadLetterQueueResource's ViewDeadLetterQueueEvent shape.
 */
class ViewExportJob extends Page
{
    protected static string $resource = ExportJobResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Export Job';

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
            $row = app(PlatformDataExportGovernanceDirectoryService::class)->findExportJob($admin, $firm, $this->id);
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
            $row = app(PlatformDataExportGovernanceDirectoryService::class)->findExportJob($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This export job could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Export Job')
                ->description('No real file is ever produced by any export in this system — this is status/manifest visibility only.')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Export type: '.Str::headline((string) $row['export_type'])),
                    Text::make('Status: '.Str::headline((string) $row['status'])),
                    Text::make('Reason: '.($row['reason'] ?? '—')),
                    // These three flags are written as a constant `true`
                    // by ExportJobService::request() regardless of what
                    // was actually evaluated: the hold/retention inputs
                    // to ExportGovernancePolicyService are
                    // caller-supplied booleans that default to false,
                    // and no legal hold or retention service is
                    // consulted by the export path itself. Rendering
                    // them as "Yes" would assert a verification that
                    // did not necessarily happen, so they are labelled
                    // for what they are — a recorded flag, not evidence
                    // of a performed check.
                    Text::make('Legal hold clearance flag: '.($row['legal_hold_checked'] ? 'Recorded' : 'Not recorded'))
                        ->color('gray'),
                    Text::make('Retention clearance flag: '.($row['retention_checked'] ? 'Recorded' : 'Not recorded'))
                        ->color('gray'),
                    Text::make('Offboarding clearance flag: '.($row['offboarding_checked'] ? 'Recorded' : 'Not recorded'))
                        ->color('gray'),
                    Text::make(
                        'These flags record that the export governance decision path ran. They are not evidence that a '
                        .'legal hold or retention lookup was performed for this export — ExportGovernancePolicyService '
                        .'receives those as caller-supplied inputs and the export path consults no hold or retention '
                        .'service of its own. Consult Legal Holds directly to establish hold state for this firm.'
                    )->color('warning'),
                    Text::make('Started at: '.($row['started_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Completed at: '.($row['completed_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Failed reason: '.($row['failed_reason'] ?? '—')),
                ]),
        ]);
    }
}
