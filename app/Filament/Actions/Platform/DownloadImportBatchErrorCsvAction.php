<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use League\Csv\Writer;

/**
 * DownloadImportBatchErrorCsvAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT6). "Download Error CSV" per this
 * mission's own spec. Mirrors the established streamDownload/League\
 * Csv pattern from PlatformIntegrationOverviewPage's own exportCsv
 * action — no new export mechanism invented. Only Invalid rows are
 * genuinely "errors" (their errors[] column is populated); Duplicate
 * rows are included too since they also need admin attention, with an
 * empty Errors column to stay honest about the distinction.
 */
class DownloadImportBatchErrorCsvAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'downloadImportBatchErrorCsv';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Download Error CSV');
        $this->icon(Heroicon::OutlinedArrowDownTray);
        $this->color('gray');

        $this->visible(fn (DirectoryImportBatch $record): bool => $record->invalid_rows > 0 || $record->duplicate_rows > 0);

        $this->action(function (DirectoryImportBatch $record, PlatformStaffAccessPolicyService $accessPolicy) {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return null;
            }

            if (! $accessPolicy->canManageMarketplaceGovernance($actor)->allowed) {
                Notification::make()->title('Not permitted')->danger()->send();

                return null;
            }

            $rows = $record->rows()
                ->whereIn('status', [DirectoryImportRowStatus::Invalid->value, DirectoryImportRowStatus::Duplicate->value])
                ->orderBy('row_number')
                ->get();

            $csv = Writer::createFromString('');
            $csv->insertOne(['Row #', 'Status', 'Display Name', 'Errors']);

            foreach ($rows as $row) {
                $csv->insertOne([
                    $row->row_number,
                    $row->status->value,
                    $row->mapped_data['display_name'] ?? $row->raw_data['display_name'] ?? '',
                    $row->errors !== null ? implode('; ', $row->errors) : '',
                ]);
            }

            $csvContent = $csv->toString();

            return response()->streamDownload(function () use ($csvContent): void {
                echo $csvContent;
            }, 'import-batch-'.$record->id.'-errors-'.now()->format('Y-m-d-His').'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        });
    }
}
