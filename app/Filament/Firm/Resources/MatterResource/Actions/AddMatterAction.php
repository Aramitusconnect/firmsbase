<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Actions;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Filament\Firm\Resources\MatterResource;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Services\MatterCreationAccessPolicyService;
use App\Services\MatterCreationService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

/**
 * AddMatterAction — the "+ Add Matter" header action closing Firm
 * Feature Manifest §2's confirmed gap (no general "create a matter"
 * service/UI existed). A custom header `Action` on ListMatters, NOT a
 * `CreateRecord` page — same structural choice AddClientAction made for
 * ClientResource, and for the same underlying reason MatterResource's
 * own docblock already states: matter status transitions must never be
 * bindable through a generic Filament form. This form never exposes a
 * `status` field at all — the record always starts in
 * `MatterStatus::Draft` (enforced by `MatterCreationService::create()`
 * itself, not just this form's omission) and can only ever reach
 * `Open` afterward via the separate, existing conflict-gated
 * "Open Matter" action (`OpenMatterAction` /
 * `MatterOpeningService::openMatter()`) — this action does not touch
 * that flow at all (mission instruction: creation and opening are two
 * separate concerns).
 *
 * Only fields backed by real `matters` columns/relations are exposed
 * (verified against the migration + model): client_id, practice area +
 * matter type (matter_type_id must belong to the chosen practice area —
 * enforced again, defense-in-depth, by MatterCreationService itself),
 * assigned_attorney_id ("Responsible Attorney"), and an optional
 * multi-select of MatterAssignment rows ("Assigned Staff"). `stage` is
 * exposed as an optional freeform text field under its own real name
 * (never relabeled "Title"/"Matter Number" — neither column exists on
 * `matters`, confirmed by direct migration read; inventing either would
 * violate this mission's explicit "do not invent columns" instruction).
 * `opened_at` is deliberately NOT exposed here — it is set exclusively
 * by MatterOpeningService::openMatter() alongside the Open status
 * transition (Matter's own docblock), so offering it at creation time
 * (while the matter is still Draft) would be misleading. Billing
 * arrangement, court/jurisdiction, description, and related contacts
 * are NOT modeled on `matters` at all and are correctly omitted, not
 * invented.
 *
 * Gated on MatterCreationAccessPolicyService::canCreateMatter()
 * (FirmOwner/Attorney/Paralegal/LegalAssistant — Firm Feature
 * Manifest's own suggested ceiling, matching
 * ClientCrmAccessPolicyService::CLIENT_MANAGEMENT_ROLES), checked both
 * in visible() and again inside action() (defense-in-depth, matching
 * every other Action in this panel).
 *
 * Tenant-context wrap: this action executes via Filament's shared
 * `livewire/update` endpoint, which carries no ambient
 * `app.current_firm_id` (see AddClientAction's docblock for the
 * confirmed root cause) — every tenant-owned (`clients`/`firm_users`)
 * options query and the create() call itself run inside an explicit
 * `runWithFirmContext()` wrap. PracticeArea/MatterType are GLOBAL
 * platform catalogs (no BelongsToTenant/RLS) and are queried plainly.
 */
class AddMatterAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addMatter';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('+ Add Matter');
        $this->icon(Heroicon::OutlinedPlus);
        $this->color('primary');
        $this->modalHeading('Add Matter');
        $this->modalDescription('Creates a new matter in Draft status. A matter can only move to Open after a completed, clear conflict check via the separate "Open Matter" action — never directly from this form.');
        $this->modalSubmitActionLabel('Add Matter');
        $this->modalWidth('2xl');

        $this->schema([
            Section::make('Matter Details')
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label('Client')
                        ->options(function (): array {
                            $firmUser = Auth::user()?->activeFirmUser();

                            if ($firmUser === null) {
                                return [];
                            }

                            return app(TenantContextService::class)->runWithFirmContext(
                                (int) $firmUser->firm_id,
                                fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all(),
                            );
                        })
                        ->searchable()
                        ->required(),

                    Select::make('primary_practice_area_id')
                        ->label('Practice Area')
                        // GLOBAL platform catalog — no BelongsToTenant, no
                        // tenant-context wrap needed (same reasoning as
                        // AddClientAction's own practice_area_interest_id
                        // field).
                        ->options(fn (): array => PracticeArea::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->required(),

                    Select::make('matter_type_id')
                        ->label('Matter Type')
                        ->options(fn (Get $get): array => filled($get('primary_practice_area_id'))
                            ? MatterType::query()
                                ->where('practice_area_id', $get('primary_practice_area_id'))
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                            : [])
                        ->searchable()
                        ->required()
                        ->helperText('Only types belonging to the selected practice area are shown.'),

                    Select::make('assigned_attorney_id')
                        ->label('Responsible Attorney')
                        ->options(function (): array {
                            $firmUser = Auth::user()?->activeFirmUser();

                            if ($firmUser === null) {
                                return [];
                            }

                            return app(TenantContextService::class)->runWithFirmContext(
                                (int) $firmUser->firm_id,
                                fn (): array => FirmUser::query()
                                    ->with('user')
                                    ->where('role', FirmUserRole::Attorney)
                                    ->where('status', FirmUserStatus::Active)
                                    ->get()
                                    ->mapWithKeys(fn (FirmUser $fu): array => [$fu->user_id => $fu->user?->name ?? "User #{$fu->user_id}"])
                                    ->all(),
                            );
                        })
                        ->searchable()
                        ->nullable(),

                    Select::make('assigned_staff_user_ids')
                        ->label('Assigned Staff')
                        ->multiple()
                        ->options(function (): array {
                            $firmUser = Auth::user()?->activeFirmUser();

                            if ($firmUser === null) {
                                return [];
                            }

                            return app(TenantContextService::class)->runWithFirmContext(
                                (int) $firmUser->firm_id,
                                fn (): array => FirmUser::query()
                                    ->with('user')
                                    ->where('status', FirmUserStatus::Active)
                                    ->get()
                                    ->mapWithKeys(fn (FirmUser $fu): array => [$fu->user_id => $fu->user?->name ?? "User #{$fu->user_id}"])
                                    ->all(),
                            );
                        })
                        ->searchable()
                        ->nullable()
                        ->helperText('Optional — creates an active staffing assignment for each selected user.'),

                    TextInput::make('stage')
                        ->label('Initial Stage (optional)')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('Freeform — practice-area templates, not this form, define stage progressions.'),
                ]),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(MatterCreationAccessPolicyService::class)->canCreateMatter($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to add a matter.')->danger()->send();

                return;
            }

            if (! app(MatterCreationAccessPolicyService::class)->canCreateMatter($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not add matters.')->danger()->send();

                return;
            }

            try {
                $matter = app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($data, $firmUser) {
                        $client = Client::query()->where('id', $data['client_id'])->first();

                        if ($client === null) {
                            throw new RuntimeException('The selected client could not be found.');
                        }

                        return app(MatterCreationService::class)->create(
                            $firmUser->firm,
                            $client,
                            (int) $data['primary_practice_area_id'],
                            (int) $data['matter_type_id'],
                            filled($data['assigned_attorney_id'] ?? null) ? (int) $data['assigned_attorney_id'] : null,
                            filled($data['stage'] ?? null) ? (string) $data['stage'] : null,
                            $data['assigned_staff_user_ids'] ?? null,
                        );
                    },
                );
            } catch (RuntimeException|InvalidArgumentException $e) {
                report($e);
                Notification::make()->title('Could not add matter')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Matter added')->success()->send();

            $this->redirect(MatterResource::getUrl('view', ['record' => $matter]));
        });
    }
}
