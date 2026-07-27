<?php

declare(strict_types=1);

namespace App\Filament\Resources\LegalHoldResource\Pages;

use App\Filament\Resources\LegalHoldResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformLegalHoldDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewLegalHold — a custom Resource page mirroring
 * DeadLetterQueueResource's ViewDeadLetterQueueEvent shape (composite
 * firmUuid+id route, TOCTOU-safe re-fetch on every render). Read-only
 * display — Place/Release actions live exclusively on the List page's
 * row/header actions, mirroring every other FORCE-RLS composite-route
 * Resource in this codebase (none of them register mutating actions on
 * their View page).
 */
class ViewLegalHold extends Page
{
    protected static string $resource = LegalHoldResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Legal Hold';

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
            $row = app(PlatformLegalHoldDirectoryService::class)->find($admin, $firm, $this->id);
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
            $row = app(PlatformLegalHoldDirectoryService::class)->find($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This legal hold could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Legal Hold')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Scope: '.Str::headline((string) $row['scope_type'])),
                    Text::make('Subject: '.$this->subjectSummary($row)),
                    Text::make('Reason: '.$row['reason']),
                    Text::make('Status: '.Str::headline((string) $row['status'])),
                    Text::make('Placed by: '.($row['placed_by_type'] ?? '—').' #'.($row['placed_by_id'] ?? '—')),
                    Text::make('Placed at: '.($row['placed_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Released by: '.($row['released_by_type'] !== null ? $row['released_by_type'].' #'.$row['released_by_id'] : '—')),
                    Text::make('Released at: '.($row['released_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Release reason: '.($row['release_reason'] ?? '—')),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function subjectSummary(array $row): string
    {
        return match ($row['scope_type']) {
            'client' => 'Client #'.$row['client_id'],
            'matter' => 'Matter #'.$row['matter_id'],
            'document' => 'Document #'.$row['document_id'],
            default => 'Entire firm',
        };
    }
}
