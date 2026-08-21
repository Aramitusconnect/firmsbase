<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\MatterAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PartiesRelationManager — Mission 5A. MatterParty
 * (`Matter::matterParties()`) had zero Filament references and zero
 * production writers before this mission (a pure UI-exposure gap, not
 * a security issue — confirmed no exploitable path exists today since
 * nothing creates rows). Mirrors ContactsRelationManager's overall
 * shape for this resource, but unlike Contacts (a read-only
 * cross-reference — ContactResource remains the full-CRUD surface for
 * Contact itself), Party has no Filament resource of its own anywhere
 * in this codebase, so this tab is the genuine list/add/edit/remove
 * surface for matter-party links — picking an EXISTING firm-scoped
 * Party via a searchable Select (the same "pick an existing related
 * record" shape AddMatterAction's own client_id Select uses), never
 * creating a Party inline.
 *
 * `parties` carries permanent FORCE ROW LEVEL SECURITY (BelongsToTenant
 * + firm_id column — see PartyFactory's own docblock and the
 * 2026_08_25_930026_force_rls_on_parties_table migration), so every
 * options-list read and every write below runs inside an explicit
 * TenantContextService::runWithFirmContext() wrap — `matter_parties`
 * itself carries no RLS/firm_id of its own (isolation is transitive
 * through matter_id -> matters.firm_id, per that migration's own
 * docblock), but the underlying Party rows it references do.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() (the real
 * per-record boundary every Matter tab checks) with
 * ClientCrmAccessPolicyService::canView()/canManageContact() — reused
 * rather than inventing a Party-specific policy service, since Party is
 * the same CRM-adjacent, non-money-moving domain Contacts/conflict
 * checks already live in and this codebase's convention is "narrow an
 * existing ceiling, don't multiply policy classes."
 */
class PartiesRelationManager extends RelationManager
{
    protected static string $relationship = 'matterParties';

    protected static ?string $title = 'Parties';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(ClientCrmAccessPolicyService::class)->canView($firmUser->role);
    }

    private function canManage(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(ClientCrmAccessPolicyService::class)->canManageContact($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('party.name')->label('Party')->placeholder('—'),
                TextColumn::make('party.entity_type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->placeholder('—'),
                TextColumn::make('relationship_type')->label('Relationship')->placeholder('—'),
                IconColumn::make('is_opposing')->label('Opposing')->boolean(),
                IconColumn::make('is_related')->label('Related')->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('addParty')
                    ->label('Add Party')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('primary')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        Select::make('party_id')
                            ->label('Party')
                            ->options(function (): array {
                                /** @var Matter $matter */
                                $matter = $this->getOwnerRecord();

                                return app(TenantContextService::class)->runWithFirmContext(
                                    (int) $matter->firm_id,
                                    fn (): array => Party::query()
                                        ->where('firm_id', $matter->firm_id)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all(),
                                );
                            })
                            ->searchable()
                            ->required(),
                        TextInput::make('relationship_type')
                            ->label('Relationship')
                            ->maxLength(255)
                            ->helperText('Freeform — e.g. "petitioner", "witness", "opposing counsel".'),
                        Toggle::make('is_opposing')->label('Opposing party'),
                        Toggle::make('is_related')->label('Related party'),
                    ])
                    ->action(function (array $data): void {
                        if (! $this->canManage()) {
                            Notification::make()->title('Not permitted')->danger()->send();

                            return;
                        }

                        /** @var Matter $matter */
                        $matter = $this->getOwnerRecord();

                        try {
                            app(TenantContextService::class)->runWithFirmContext(
                                (int) $matter->firm_id,
                                function () use ($matter, $data): void {
                                    $party = Party::query()
                                        ->where('firm_id', $matter->firm_id)
                                        ->where('id', $data['party_id'])
                                        ->first();

                                    if ($party === null) {
                                        throw new RuntimeException('The selected party could not be found.');
                                    }

                                    if (MatterParty::query()->where('matter_id', $matter->id)->where('party_id', $party->id)->exists()) {
                                        throw new RuntimeException('This party is already linked to this matter.');
                                    }

                                    MatterParty::create([
                                        'matter_id' => $matter->id,
                                        'party_id' => $party->id,
                                        'relationship_type' => filled($data['relationship_type'] ?? null) ? (string) $data['relationship_type'] : null,
                                        'is_opposing' => (bool) ($data['is_opposing'] ?? false),
                                        'is_related' => (bool) ($data['is_related'] ?? false),
                                    ]);
                                },
                            );

                            Notification::make()->title('Party added')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Could not add party')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('removeParty')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canManage())
                    ->action(function (MatterParty $record): void {
                        if (! $this->canManage()) {
                            Notification::make()->title('Not permitted')->danger()->send();

                            return;
                        }

                        /** @var Matter $matter */
                        $matter = $this->getOwnerRecord();

                        if ((int) $record->matter_id !== (int) $matter->id) {
                            Notification::make()->title('This party is not linked to this matter.')->danger()->send();

                            return;
                        }

                        app(TenantContextService::class)->runWithFirmContext(
                            (int) $matter->firm_id,
                            fn () => MatterParty::query()
                                ->where('id', $record->id)
                                ->where('matter_id', $matter->id)
                                ->delete(),
                        );

                        Notification::make()->title('Party removed')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
