<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Jobs\ScanDocumentJob;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use App\Services\DocumentSecurityService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * PlaidUploadFallbackPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.8). File upload for
 * statements/CSV/pay stubs/liability docs/investment statements —
 * routed through the EXISTING `DocumentUploadPolicyService`
 * (via `DocumentSecurityService::upload()`, which calls
 * `assertUploadIsAllowed()` internally) -> real multipart HTTP handling
 * (Livewire `FileUpload`) -> `Storage::disk()->putFileAs()` -> a real
 * computed sha256 hash -> `DocumentSecurityService::upload()` ->
 * `ScanDocumentJob`. Uploaded rows are ordinary `Document` rows tagged
 * `matter_id`; provenance = `UploadedSourceRecord` everywhere they
 * surface in the Workspace — an explicit, visually distinct badge/label
 * ("Client-uploaded — not bank-verified") so nothing here is ever
 * confused with `ProviderSuppliedFact` data.
 */
class PlaidUploadFallbackPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Url]
    public ?string $matter = null;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Upload Financial Documents';

    public ?array $data = [];

    public function mount(): void
    {
        $this->resolveMatterOrFail();
        $this->form->fill([]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Upload financial documents')
                ->description('Client-uploaded documents — not bank-verified. Accepted: statements, CSV, pay stubs, liability documents, investment statements.')
                ->schema([
                    EmbeddedSchema::make('form'),
                    SchemaActions::make([
                        Action::make('upload')->label('Upload')->action('handleUpload'),
                    ]),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file')
                    ->label('Document')
                    ->disk('local')
                    ->directory('client-portal-uploads')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'text/csv', 'image/jpeg', 'image/png', 'image/tiff', 'text/plain'])
                    ->maxSize(25_600)
                    ->required(),
            ]);
    }

    public function handleUpload(): void
    {
        $matterModel = $this->resolveMatterOrFail();
        /** @var ClientPortalUser $portalUser */
        $portalUser = Auth::guard('client')->user();

        $state = $this->form->getState();
        $file = $state['file'] ?? null;

        if (! is_string($file) || $file === '') {
            Notification::make()->title('No file selected.')->danger()->send();

            return;
        }

        if (! Storage::disk('local')->exists($file)) {
            Notification::make()->title('Uploaded file could not be found.')->danger()->send();

            return;
        }

        $absolutePath = Storage::disk('local')->path($file);
        $originalFilename = basename($file);
        $mimeType = Storage::disk('local')->mimeType($file) ?: 'application/octet-stream';
        $sizeBytes = Storage::disk('local')->size($file);
        $fileHash = hash_file('sha256', $absolutePath) ?: hash('sha256', $file);

        // Move into a durable, matter-scoped path — the temporary
        // Livewire upload path is not the final storage location.
        $finalPath = 'client-portal-uploads/'.$matterModel->firm_id.'/'.$matterModel->id.'/'.Str::uuid7().'-'.$originalFilename;
        Storage::disk('local')->move($file, $finalPath);

        try {
            $document = (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => app(DocumentSecurityService::class)->upload(
                firm: $matterModel->firm,
                originalFilename: $originalFilename,
                mimeType: $mimeType,
                sizeBytes: (int) $sizeBytes,
                storageDisk: 'local',
                storagePath: $finalPath,
                fileHash: $fileHash,
                matter: $matterModel,
                client: $portalUser->client,
            ));

            ScanDocumentJob::dispatch($document->id);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Upload rejected')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->form->fill([]);
        Notification::make()->title('Document uploaded — provenance: Client-uploaded, not bank-verified')->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): \Illuminate\Support\Collection {
                $matterModel = $this->resolveMatterOrFail();

                return (new TenantContextService)->runWithFirmContext($matterModel->firm_id, fn () => Document::query()
                    ->where('matter_id', $matterModel->id)
                    ->orderByDesc('created_at')
                    ->get());
            })
            ->columns([
                TextColumn::make('original_filename')->label('Document'),
                TextColumn::make('status')->badge(),
                TextColumn::make('scan_status')->label('Scan')->badge(),
                TextColumn::make('created_at')->label('Uploaded')->dateTime(),
                TextColumn::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->color('warning')
                    ->state('Client-uploaded — not bank-verified'),
            ])
            ->emptyStateHeading('No documents uploaded yet')
            ->paginated(false);
    }

    private function resolveMatterOrFail(): Matter
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null || $this->matter === null) {
            throw new AccessDeniedHttpException('No matter specified.');
        }

        $matterId = (int) $this->matter;
        $matterModel = (new TenantContextService)->runWithFirmContext($portalUser->client->firm_id, fn () => Matter::query()->find($matterId));

        if ($matterModel === null || ! app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matterModel)) {
            throw new AccessDeniedHttpException('You do not have access to this matter.');
        }

        return $matterModel;
    }
}
