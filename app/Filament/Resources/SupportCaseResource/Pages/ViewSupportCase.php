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

    /**
     * FINAL ADMIN RECONCILIATION naming-truth follow-through. Prompt 6
     * corrected SupportCaseResource's navigation label to "Access
     * Requests" because there is no SupportCase model, table, service
     * or ticket domain anywhere in this codebase — the resource reads
     * `support_access_requests` and nothing else. This detail page's
     * own visible title was missed by that pass, so the list said
     * "Access Requests" while the record it opened was headed "Support
     * Case", reintroducing the same non-existent domain one click
     * later. The page's own body section already reads "Support Access
     * Request".
     *
     * Only the user-visible string changes here. The class, namespace,
     * slug and route are deliberately left alone, exactly as Prompt 6
     * reasoned: they are technical identifiers with existing test and
     * route references.
     */
    protected static ?string $title = 'Support Access Request';

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
            Text::make('Standard-access approval and denial happen on the firm side, in the firm\'s own Support Access page — a firm owner decides, never platform staff. This console can view request status and mark stale requests Expired.')
                ->color('gray'),
            Section::make('Support Access Request')
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
