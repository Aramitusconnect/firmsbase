<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\SignatureRequestResource\Actions;

use App\Filament\Firm\Resources\SignatureRequestResource;
use App\Models\Client;
use App\Models\Document;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Services\SignatureAndPdfAccessPolicyService;
use App\Services\SignatureRequestWorkflowService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateSignatureRequestAction — the "+ New Signature Request" header
 * action, wired directly to SignatureRequestWorkflowService::create() —
 * never a bare SignatureRequest::create(). `source` collapses the
 * service's exactly-one-of-$document/$generatedDocument XOR into a
 * single required Select (each option tagged "document:{id}" or
 * "generated:{id}") rather than two parallel selects with one always
 * disabled — one honest field beats a toggle pretending two fields are
 * independent when the service only ever accepts one.
 */
class CreateSignatureRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createSignatureRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ New Signature Request');
        $this->modalHeading('New Signature Request');
        $this->modalSubmitActionLabel('Create Request');
        $this->modalWidth('lg');
        $this->icon(Heroicon::OutlinedPlus);
        $this->color('primary');

        $this->schema([
            TextInput::make('title')
                ->label('Title')
                ->required(),

            Select::make('client_id')
                ->label('Client (optional)')
                ->options(fn (): array => self::firmScoped(fn () => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all()))
                ->searchable()
                ->live(),

            Select::make('matter_id')
                ->label('Matter (optional)')
                ->options(fn (Get $get): array => filled($get('client_id'))
                    ? self::firmScoped(fn () => Matter::query()
                        ->where('client_id', $get('client_id'))
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [$matter->id => $matter->stage ?? "Matter #{$matter->id}"])
                        ->all())
                    : [])
                ->searchable()
                ->helperText('Only matters belonging to the selected client are shown.'),

            Select::make('source')
                ->label('Source Document')
                ->options(fn (): array => self::firmScoped(fn () => self::sourceDocumentOptions()))
                ->searchable()
                ->required()
                ->helperText('Documents already uploaded to a matter, and documents produced from a template.'),

            DatePicker::make('expires_at')
                ->label('Expires On (optional)')
                ->native(false)
                ->minDate(now()->addDay()),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(SignatureAndPdfAccessPolicyService::class)->canManageRequests($firmUser);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(SignatureAndPdfAccessPolicyService::class)->canManageRequests($firmUser)) {
                Notification::make()->title('Not permitted')->body('Your role may not create signature requests.')->danger()->send();

                return;
            }

            $request = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($data, $firmUser) {
                    $client = filled($data['client_id'] ?? null)
                        ? Client::query()->where('id', $data['client_id'])->where('firm_id', $firmUser->firm_id)->first()
                        : null;

                    $matter = filled($data['matter_id'] ?? null)
                        ? Matter::query()->where('id', $data['matter_id'])->where('firm_id', $firmUser->firm_id)->first()
                        : null;

                    [$document, $generatedDocument] = self::resolveSource((string) $data['source'], (int) $firmUser->firm_id);

                    if ($document === null && $generatedDocument === null) {
                        return null;
                    }

                    return app(SignatureRequestWorkflowService::class)->create(
                        firm: $firmUser->firm,
                        title: (string) $data['title'],
                        requestedBy: $firmUser,
                        document: $document,
                        generatedDocument: $generatedDocument,
                        matter: $matter,
                        client: $client,
                        expiresAt: filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
                    );
                },
            );

            if ($request === null) {
                Notification::make()->title('Could not create signature request')->body('The selected source document could not be found for your firm.')->danger()->send();

                return;
            }

            Notification::make()->title('Signature request created')->success()->send();

            $this->redirect(SignatureRequestResource::getUrl('view', ['record' => $request]));
        });
    }

    /**
     * @return array<int|string, string>
     */
    private static function sourceDocumentOptions(): array
    {
        $documents = Document::query()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Document $document): array => ['document:'.$document->id => 'Document: '.$document->original_filename]);

        $generated = GeneratedDocument::query()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (GeneratedDocument $generatedDocument): array => ['generated:'.$generatedDocument->id => 'Generated Document: #'.$generatedDocument->id]);

        return $documents->union($generated)->all();
    }

    /**
     * @return array{0: ?Document, 1: ?GeneratedDocument}
     */
    private static function resolveSource(string $source, int $firmId): array
    {
        [$type, $id] = array_pad(explode(':', $source, 2), 2, null);

        if ($type === 'document' && $id !== null) {
            $document = Document::query()->where('id', $id)->where('firm_id', $firmId)->first();

            return [$document, null];
        }

        if ($type === 'generated' && $id !== null) {
            $generatedDocument = GeneratedDocument::query()->where('id', $id)->where('firm_id', $firmId)->first();

            return [null, $generatedDocument];
        }

        return [null, null];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function firmScoped(callable $callback)
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, $callback);
    }
}
