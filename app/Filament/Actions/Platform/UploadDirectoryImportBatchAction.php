<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Resources\DirectoryImportBatchResource;
use App\Marketplace\Services\MarketplaceCsvIngestionService;
use App\Marketplace\Services\MarketplaceImportDuplicateDetectionService;
use App\Marketplace\Services\MarketplaceImportValidationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * UploadDirectoryImportBatchAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT6). "New Import"/"Upload Directory
 * Data" per this mission's own spec — the CSV ingestion pipeline
 * (MarketplaceCsvIngestionService, MarketplaceImportValidationService,
 * MarketplaceImportDuplicateDetectionService) has existed since
 * Mission 2 checkpoint 11 with NO Filament UI at all reaching it
 * before this mission — batches could only ever be created by a test
 * or a console script. This wires the ONE existing importer end to
 * end (ingest -> validate -> detect duplicates), landing the new
 * batch in Previewed status, ready for ConfirmImportSourceRights +
 * Apply — never a second/parallel import path.
 *
 * FileUpload wiring mirrors the established pattern in
 * app/Filament/ClientPortal/Pages/PlaidUploadFallbackPage.php: Filament
 * stores the file to disk immediately and the form state holds a
 * stored path (string), not a raw UploadedFile — so the action
 * reconstructs an Illuminate\Http\UploadedFile pointing at that
 * already-stored file (test: true, since it did not arrive via a real
 * HTTP multipart request at this point) before handing it to
 * MarketplaceCsvIngestionService::ingest(), which immediately moves it
 * into its own quarantine path, scans it, and deletes the original —
 * this action never bypasses that scan/quarantine step.
 *
 * MyAttorney final hardening mission, finding 5: this field
 * deliberately does NOT call ->preserveFilenames() — Filament's own
 * vendor docblock on that method states plainly that "preserving
 * user-provided filenames on local ... disks can allow PHP file
 * execution" and recommends exactly the fix applied here:
 * ->storeFileNamesIn() to capture the true original client filename
 * into its own Livewire state key while Filament stores the temp
 * upload itself under a random ULID name. MarketplaceCsvIngestionService
 * independently sanitizes whatever original filename it is given
 * before ever using it in a storage path (defense in depth — this
 * action's own fix does not depend on the service's, or vice versa).
 */
class UploadDirectoryImportBatchAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'uploadDirectoryImportBatch';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('New Import');
        $this->icon(Heroicon::OutlinedArrowUpTray);
        $this->schema([
            FileUpload::make('file')
                ->label('Directory Data (CSV)')
                ->disk('local')
                ->directory('marketplace-imports/uploads')
                ->visibility('private')
                ->storeFileNamesIn('original_client_filename')
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                ->maxSize(25_600)
                ->required(),
        ]);
        $this->modalDescription('Uploads, virus-scans, validates, and checks for duplicates against the existing directory. The batch will require Source Rights confirmation before it can be applied.');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, PlatformAdminAuditEventRecorder $audit): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $decision = $accessPolicy->canManageMarketplaceGovernance($actor);
            if (! $decision->allowed) {
                Notification::make()->title('Not permitted')->body($decision->reason)->danger()->send();

                return;
            }

            $storedPath = $data['file'] ?? null;
            if (! is_string($storedPath) || $storedPath === '' || ! Storage::disk('local')->exists($storedPath)) {
                Notification::make()->title('Uploaded file could not be found.')->danger()->send();

                return;
            }

            // The FileUpload field no longer calls ->preserveFilenames()
            // (see this class's own docblock — finding 5), so
            // $storedPath is a random ULID-named file; the true
            // original client filename lives in the separate
            // storeFileNamesIn() state key instead. Falls back to the
            // (safe, ULID) stored basename only if that key is somehow
            // absent, never to any part of the raw client path.
            $originalFilename = is_string($data['original_client_filename'] ?? null) && $data['original_client_filename'] !== ''
                ? $data['original_client_filename']
                : basename($storedPath);
            $absolutePath = Storage::disk('local')->path($storedPath);
            $mimeType = Storage::disk('local')->mimeType($storedPath) ?: 'text/csv';
            $uploadedFile = new UploadedFile($absolutePath, $originalFilename, $mimeType, null, true);

            try {
                $batch = app(MarketplaceCsvIngestionService::class)->ingest($uploadedFile, $actor);
                $batch = app(MarketplaceImportValidationService::class)->validateBatch($batch, $actor);
                $batch = app(MarketplaceImportDuplicateDetectionService::class)->detectBatch($batch, $actor);

                Notification::make()->title('Import batch created: '.$batch->valid_rows.' valid, '.$batch->invalid_rows.' invalid, '.$batch->duplicate_rows.' duplicate row(s).')->success()->send();

                $this->redirect(DirectoryImportBatchResource::getUrl('view', ['record' => $batch]));
            } catch (\Throwable $e) {
                $audit->recordPlatformEvent($actor, 'marketplace_import_rejected', 'marketplace_import', [
                    'original_filename' => $originalFilename,
                    'reason' => $e->getMessage(),
                ]);

                Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();
            } finally {
                Storage::disk('local')->delete($storedPath);
            }
        });
    }
}
