<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeletionRequestResource\Pages;

use App\Enums\DeletionRequestStatus;
use App\Filament\Resources\DeletionRequestResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformDeletionRequestDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewDeletionRequest — composite firmUuid+id route, read-only display
 * (mutating actions live on the List page's row actions, mirroring
 * every other FORCE-RLS composite-route Resource in this codebase).
 * Shows the nested DeletionApproval record inline. ReadyForExecution is
 * always labeled "Approved for execution" here — never "Deleted."
 */
class ViewDeletionRequest extends Page
{
    protected static string $resource = DeletionRequestResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Deletion Request';

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
            $row = app(PlatformDeletionRequestDirectoryService::class)->find($admin, $firm, $this->id);
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
            $row = app(PlatformDeletionRequestDirectoryService::class)->find($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This deletion request could not be found.')->color('danger'),
            ]);
        }

        $statusLabel = $row['status'] === DeletionRequestStatus::ReadyForExecution->value
            ? 'Approved for execution (terminal state — this system never physically deletes the underlying record)'
            : Str::headline((string) $row['status']);

        $components = [
            Section::make('Deletion Request')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Subject: '.$row['subject_type'].' #'.$row['subject_id']),
                    Text::make('Reason: '.$row['reason']),
                    Text::make('Status: '.$statusLabel),
                    Text::make('Requested by: '.($row['requested_by_type'] ?? '—').' #'.($row['requested_by_id'] ?? '—')),
                    Text::make('Requested at: '.($row['requested_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Cancelled at: '.($row['cancelled_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ];

        $approval = $row['approval'];

        $components[] = Section::make('Deletion Approval')
            ->description('Two-person, high-risk-change approval workflow (HighRiskChangeType::ProductionDataDeletion).')
            ->columns(2)
            ->schema($approval === null ? [
                Text::make('No approval has been requested yet.'),
            ] : [
                Text::make('Status: '.Str::headline((string) $approval['status'])),
                Text::make('First approved by: #'.($approval['first_approved_by'] ?? '—')),
                Text::make('First approved at: '.($approval['first_approved_at']?->toDayDateTimeString() ?? '—')),
                Text::make('Second approved by: #'.($approval['second_approved_by'] ?? '—')),
                Text::make('Second approved at: '.($approval['second_approved_at']?->toDayDateTimeString() ?? '—')),
                Text::make('Denied by: '.($approval['denied_by'] !== null ? '#'.$approval['denied_by'] : '—')),
                Text::make('Denied at: '.($approval['denied_at']?->toDayDateTimeString() ?? '—')),
                Text::make('Denial reason: '.($approval['denial_reason'] ?? '—')),
            ]);

        return $schema->components($components);
    }
}
