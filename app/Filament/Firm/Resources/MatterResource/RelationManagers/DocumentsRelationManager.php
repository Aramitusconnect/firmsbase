<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Jobs\ScanDocumentJob;
use App\Models\Document;
use App\Models\Matter;
use App\Services\DocumentHashService;
use App\Services\DocumentSecurityService;
use App\Services\MatterAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * DocumentsRelationManager — Checkpoint 4 ("Plaid financial evidence
 * add-on"), "Documents" tab. Unlike FirmIntegrationResource's
 * RelationManagers, `Matter::documents()` is a real, already-defined
 * `HasMany` (`Matter.php`), so Filament's default `getRelationship()`
 * (driven by `$relationship` below) is sufficient — no manual `HasMany`
 * construction needed.
 *
 * Mission 3 (Document Center Completion), section 3.1: this used to be
 * read-only, with a docblock claiming "document upload has no HTTP/UI
 * entry point anywhere in this codebase yet." That claim was wrong —
 * `PlaidUploadFallbackPage` (Client Portal) already proved the real,
 * working chain (Filament `FileUpload` -> `Storage::disk('local')`
 * durable move -> sha256 -> `DocumentSecurityService::upload()` inside
 * `TenantContextService::runWithFirmContext()` -> `ScanDocumentJob`) —
 * it just had no Firm-side counterpart. `upload` below is that
 * counterpart, mirroring that same chain 1:1 for a `Matter`-scoped
 * upload instead of a Client Portal one, with a `documents/{firm_id}/
 * {matter_id}/{uuid7}-{filename}` storage path instead of that page's
 * `client-portal-uploads/...` one.
 *
 * `download` (section 3.5) resolves to the real, session-authenticated
 * `firm.documents.download` route — `DocumentSecurityService::
 * canBeDownloadedBy()` is the actual authorization boundary there, not
 * this action's own `->visible()` (which is only a UX-level narrowing,
 * same "list is UX filter, the real check lives elsewhere" split this
 * codebase already draws everywhere else).
 *
 * `shareWithClient`/`unshareFromClient` (section 3.4) is the one UI
 * entry point for `DocumentSecurityService::setClientVisibility()` —
 * the flag the Client Portal's own document list (a different mission)
 * depends on. Only offered for a matter-scoped document: portal access
 * is always via an explicit `ClientPortalMatterAccessPolicyService`
 * grant on a matter, and no matter-independent grant concept exists
 * (see `canBeViewedInPortalBy()`'s own docblock), so a matterless
 * document could never actually become portal-visible regardless of
 * this flag.
 *
 * Document generation (3.6) and e-signature (3.7) UI are deliberately
 * NOT built here — out of this checkpoint's scope, and, per Mission 3's
 * own research, would currently be misleading UI on top of unfinished
 * backends (`DocumentGenerationService::generate()` produces no real
 * file; e-signature has no working signer-facing delivery mechanism).
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')->label('File')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                TextColumn::make('scan_status')
                    ->label('Scan')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'clean' => 'success',
                        'pending' => 'gray',
                        'infected', 'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('uploadedBy.name')->label('Uploaded by')->placeholder('—'),
                TextColumn::make('client_visible')
                    ->label('Client visibility')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Shared with client' : 'Not shared')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('upload')
                    ->label('Upload document')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->form([
                        FileUpload::make('file')
                            ->label('Document')
                            ->disk('local')
                            ->directory('documents-tmp')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg',
                                'image/png',
                                'image/tiff',
                                'text/plain',
                            ])
                            ->maxSize(25_600)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->handleUpload($data['file'] ?? null);
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (Document $record): string => route('firm.documents.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => app(DocumentSecurityService::class)->canBeDownloadedBy($record, Auth::user())),
                Action::make('toggleClientVisibility')
                    ->label(fn (Document $record): string => $record->client_visible ? 'Unshare from client' : 'Share with client')
                    ->icon(fn (Document $record) => $record->client_visible ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedEye)
                    ->color(fn (Document $record): string => $record->client_visible ? 'gray' : 'success')
                    ->visible(fn (Document $record): bool => $record->matter_id !== null && $record->isUsable())
                    ->requiresConfirmation()
                    ->action(function (Document $record): void {
                        $updated = app(DocumentSecurityService::class)->setClientVisibility($record, ! $record->client_visible);

                        Notification::make()
                            ->title($updated->client_visible ? 'Shared with client' : 'Unshared from client')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private function handleUpload(?string $file): void
    {
        if (! is_string($file) || $file === '') {
            Notification::make()->title('No file selected.')->danger()->send();

            return;
        }

        if (! Storage::disk('local')->exists($file)) {
            Notification::make()->title('Uploaded file could not be found.')->danger()->send();

            return;
        }

        /** @var Matter $matter */
        $matter = $this->getOwnerRecord();
        $uploader = Auth::user();

        $absolutePath = Storage::disk('local')->path($file);
        $originalFilename = basename($file);
        $mimeType = Storage::disk('local')->mimeType($file) ?: 'application/octet-stream';
        $sizeBytes = Storage::disk('local')->size($file);
        $fileHash = hash_file('sha256', $absolutePath) ?: hash('sha256', $file);

        // Move into a durable, matter-scoped path — the temporary
        // Livewire upload path is not the final storage location.
        // Mirrors PlaidUploadFallbackPage::handleUpload()'s identical
        // "temp dir, then move" pattern, keyed to firm_id/matter_id
        // instead of client-portal-uploads.
        $finalPath = 'documents/'.$matter->firm_id.'/'.$matter->id.'/'.Str::uuid7().'-'.$originalFilename;
        Storage::disk('local')->move($file, $finalPath);

        try {
            $document = (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => app(DocumentSecurityService::class)->upload(
                firm: $matter->firm,
                originalFilename: $originalFilename,
                mimeType: $mimeType,
                sizeBytes: (int) $sizeBytes,
                storageDisk: 'local',
                storagePath: $finalPath,
                fileHash: $fileHash,
                matter: $matter,
                client: $matter->client,
                uploadedBy: $uploader,
            ));

            (new DocumentHashService)->recordForDocument($document, $fileHash, $uploader?->activeFirmUser());

            ScanDocumentJob::dispatch($document->id, $matter->firm_id);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Upload rejected')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Document uploaded')->success()->send();
    }
}
