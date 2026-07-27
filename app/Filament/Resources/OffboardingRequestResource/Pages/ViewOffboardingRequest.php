<?php

declare(strict_types=1);

namespace App\Filament\Resources\OffboardingRequestResource\Pages;

use App\Filament\Actions\Platform\MarkOffboardingExportVerifiedAction;
use App\Filament\Resources\OffboardingRequestResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformDataExportGovernanceDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewOffboardingRequest — composite firmUuid+id route. Unlike most
 * other View pages in this mission (pure display), this one ALSO
 * implements HasTable/InteractsWithTable to embed the nested
 * `offboarding_exports` list (with the Verify row action) — mirroring
 * PlatformFirmIntegrationDetailPage's own established
 * "Page implements HasTable" drill-down shape. Exports are read via
 * PlatformDataExportGovernanceDirectoryService::findOffboardingRequest()'s
 * 'exports' key, which is itself already joined through the RLS-covered
 * offboarding_requests parent (see that service's own docblock) — never
 * an independent offboarding_exports query.
 */
class ViewOffboardingRequest extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = OffboardingRequestResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Offboarding Request';

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
            $row = app(PlatformDataExportGovernanceDirectoryService::class)->findOffboardingRequest($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($row === null) {
            abort(404);
        }
    }

    private function currentRow(): ?array
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return null;
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            return app(PlatformDataExportGovernanceDirectoryService::class)->findOffboardingRequest($admin, $firm, $this->id);
        } catch (RuntimeException) {
            return null;
        }
    }

    public function content(Schema $schema): Schema
    {
        $row = $this->currentRow();

        if ($row === null) {
            return $schema->components([
                Text::make('This offboarding request could not be found, or you are not permitted to view it.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Offboarding Request')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Status: '.Str::headline((string) $row['status'])),
                    Text::make('Reason: '.$row['reason']),
                    Text::make('Requested at: '.($row['requested_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Completed at: '.($row['completed_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Cancelled at: '.($row['cancelled_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Cancelled reason: '.($row['cancelled_reason'] ?? '—')),
                ]),
            Section::make('Offboarding Exports')
                ->description('No real file is ever produced by any export in this system — package_manifest_json is a declared list of data-category strings only.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $row = $this->currentRow();

                if ($row === null) {
                    return collect();
                }

                return collect($row['exports'])->map(function (array $export): array {
                    // Pre-joined into a plain string here, deliberately —
                    // Filament's TextColumn detects an array-shaped state
                    // and formats/iterates each element separately (once
                    // per array item, not once for the whole array),
                    // which would make a single formatStateUsing()
                    // closure receive one manifest ENTRY at a time
                    // (e.g. 'clients', then 'matters') rather than the
                    // whole list — joining here avoids that entirely.
                    $export['package_manifest_json'] = empty($export['package_manifest_json'])
                        ? null
                        : implode(', ', $export['package_manifest_json']);

                    return array_merge($export, ['firm_uuid' => $this->firmUuid]);
                })->values();
            })
            ->columns([
                TextColumn::make('id')->label('Export id'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('package_manifest_json')->label('Manifest')->placeholder('—'),
                TextColumn::make('generated_at')->label('Generated at')->dateTime()->placeholder('—'),
                TextColumn::make('verified_at')->label('Verified at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                MarkOffboardingExportVerifiedAction::make(),
            ])
            ->emptyStateHeading('No exports generated for this offboarding request yet')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated(false);
    }
}
