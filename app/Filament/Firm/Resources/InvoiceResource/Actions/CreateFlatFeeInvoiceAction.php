<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Actions;

use App\Filament\Firm\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\Matter;
use App\Services\BillingAccessPolicyService;
use App\Services\InvoiceDraftingService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateFlatFeeInvoiceAction — the "+ Create Flat-Fee Invoice" header
 * action on ListInvoices, wired directly to
 * InvoiceDraftingService::createFlatFee() — never a bare
 * `Invoice::create()`. A distinct Action from DraftFromTimeEntriesAction
 * (see InvoiceResource's own docblock for why: the two service methods
 * take genuinely different argument shapes, so two small honest forms
 * beat one form pretending to be two).
 */
class CreateFlatFeeInvoiceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createFlatFeeInvoice';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Create Flat-Fee Invoice');
        $this->modalHeading('Create Flat-Fee Invoice');
        $this->modalDescription('Creates a new draft invoice with a single flat-fee line for the amount below.');
        $this->modalSubmitActionLabel('Create Invoice');
        $this->modalWidth('lg');
        $this->icon(Heroicon::OutlinedDocumentPlus);
        $this->color('gray');

        $this->schema([
            Select::make('client_id')
                ->label('Client')
                ->options(fn (): array => self::firmScoped(fn () => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all()))
                ->searchable()
                ->required()
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
                ->nullable()
                ->helperText('Only matters belonging to the selected client are shown.'),

            Textarea::make('description')
                ->label('Description')
                ->required()
                ->rows(2),

            TextInput::make('amount')
                ->label('Amount')
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->required(),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(BillingAccessPolicyService::class)->canDraftInvoice($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not draft invoices.')->danger()->send();

                return;
            }

            $invoice = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($data, $firmUser) {
                    $client = Client::query()->where('id', $data['client_id'])->first();

                    if ($client === null || (int) $client->firm_id !== (int) $firmUser->firm_id) {
                        return null;
                    }

                    $matter = filled($data['matter_id'] ?? null)
                        ? Matter::query()->where('id', $data['matter_id'])->where('client_id', $client->id)->first()
                        : null;

                    return app(InvoiceDraftingService::class)->createFlatFee(
                        firm: $firmUser->firm,
                        client: $client,
                        description: (string) $data['description'],
                        amountCents: (int) round(((float) $data['amount']) * 100),
                        matter: $matter,
                        createdBy: $firmUser->user,
                    );
                },
            );

            if ($invoice === null) {
                Notification::make()->title('Could not create invoice')->body('The selected client could not be found for your firm.')->danger()->send();

                return;
            }

            Notification::make()->title('Flat-fee invoice created')->success()->send();

            $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
        });
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
