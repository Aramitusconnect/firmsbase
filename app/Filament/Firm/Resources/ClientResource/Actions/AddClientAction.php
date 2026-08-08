<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\Actions;

use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\ClientResource\Support\ClientConversionFormFields;
use App\Models\FirmLead;
use App\Models\LeadSource;
use App\Models\PracticeArea;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\LeadConversionService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * AddClientAction — the product-required "+ Add Client" primary
 * action (Firm Feature Manifest §1 / this mission's rule #2). Looks
 * and feels like "adding a client" to the user, but NEVER directly
 * instantiates the `Client` model via its own `create()` method: it
 * (a) creates the required `FirmLead` row from the Intake section of
 * this same form, then (b) immediately calls
 * `LeadConversionService::convert()` with the Client Profile section's
 * fields as `$clientAttributes` — the one and only codepath in this
 * codebase that may ever create a `Client` row (see that service's
 * own docblock: "a lead must not silently become a client any other
 * way").
 *
 * A custom header `Action` on ListClients, NOT a `CreateRecord` page —
 * ClientResource declares no 'create' route at all, so there is no
 * Filament-generic form-bound Create path that could ever bind
 * directly to the `Client` model's own create method in the first
 * place.
 *
 * Gated on `ClientCrmAccessPolicyService::canConvertLead()` — the
 * same ceiling `FirmLeadResource\Actions\ConvertLeadToClientAction`
 * checks — both visible() and, again, inside the action() closure
 * itself (defense-in-depth, matching every other Action in this
 * panel).
 *
 * Tenant-context wrap: this action executes via Filament's shared
 * `livewire/update` endpoint, which carries no ambient
 * `app.current_firm_id` (see WrapsRecordMutationInFirmContext's
 * docblock for the full, already-confirmed root cause in this
 * codebase) — both the `FirmLead::create()` call AND the
 * `LeadConversionService::convert()` call below run inside one
 * `runWithFirmContext()` wrap. `convert()` itself also calls
 * `runWithFirmContext()` internally; that nested call is safe/
 * re-entrant by design (see `TenantContextService::runWithFirmContext()`'s
 * own docblock: "it may be nested inside an already-active ambient
 * context").
 */
class AddClientAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addClient';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Add Client');
        $this->icon(Heroicon::OutlinedPlus);
        $this->color('primary');
        $this->modalHeading('Add Client');
        $this->modalDescription('This creates an intake (Lead) record and immediately converts it into a Client — a Client is never created directly, per this firm\'s intake policy.');
        $this->modalSubmitActionLabel('Add Client');
        $this->modalWidth('2xl');

        $this->schema([
            Section::make('Intake')
                ->columns(2)
                ->schema([
                    TextInput::make('intake_name')
                        ->label('Name (as given at intake)')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('intake_email')->label('Intake Email')->email()->maxLength(255),
                    TextInput::make('intake_phone')->label('Intake Phone')->maxLength(255),
                    Select::make('lead_source_id')
                        ->label('Lead Source')
                        // Deliberately NOT ->relationship()/->preload():
                        // this Select lives inside a modal Action, whose
                        // schema is built via Filament's shared
                        // `livewire/update` endpoint (mountAction()), not
                        // the page's own initial HTTP GET — see
                        // WrapsRecordMutationInFirmContext's docblock for
                        // why that endpoint carries no ambient
                        // app.current_firm_id. lead_sources is
                        // BelongsToTenant, so its options are fetched via
                        // an explicit, narrow runWithFirmContext() wrap
                        // instead.
                        ->options(function (): array {
                            $firmUser = Auth::user()?->activeFirmUser();

                            if ($firmUser === null) {
                                return [];
                            }

                            return app(TenantContextService::class)->runWithFirmContext(
                                (int) $firmUser->firm_id,
                                fn (): array => LeadSource::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
                            );
                        })
                        ->searchable()
                        ->nullable(),
                    Select::make('practice_area_interest_id')
                        ->label('Practice Area')
                        // PracticeArea is a GLOBAL platform catalog (no
                        // BelongsToTenant, no RLS) — a plain query is safe
                        // with no tenant-context wrap, unlike lead_source_id
                        // above.
                        ->options(fn (): array => PracticeArea::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->nullable(),
                ]),
            Section::make('Client Profile')
                ->columns(2)
                ->schema(ClientConversionFormFields::schema()),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to add a client.')->danger()->send();

                return;
            }

            if (! app(ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not add clients.')->danger()->send();

                return;
            }

            $client = app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($data, $firmUser) {
                    $lead = FirmLead::create([
                        'firm_id' => $firmUser->firm_id,
                        'name' => $data['intake_name'],
                        'email' => $data['intake_email'] ?: null,
                        'phone' => $data['intake_phone'] ?: null,
                        'lead_source_id' => $data['lead_source_id'] ?? null,
                        'practice_area_interest_id' => $data['practice_area_interest_id'] ?? null,
                    ]);

                    try {
                        return app(LeadConversionService::class)->convert(
                            $lead,
                            ClientConversionFormFields::extractClientAttributes($data),
                            $firmUser->user,
                        );
                    } catch (RuntimeException $e) {
                        report($e);

                        return null;
                    }
                },
            );

            if ($client === null) {
                Notification::make()->title('Could not add client')->danger()->send();

                return;
            }

            Notification::make()->title('Client added')->success()->send();

            $this->redirect(ClientResource::getUrl('view', ['record' => $client]));
        });
    }
}
