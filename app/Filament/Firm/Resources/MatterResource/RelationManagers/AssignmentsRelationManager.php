<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Enums\FirmUserStatus;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Services\MatterAccessPolicyService;
use App\Services\MatterAssignmentService;
use App\Services\MatterCreationAccessPolicyService;
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

/**
 * AssignmentsRelationManager — Mission 5A. Post-creation staffing
 * management for MatterAssignment (`Matter::matterAssignments()`),
 * which before this mission could only be populated at matter-creation
 * time via MatterCreationService::create()'s own optional staff list.
 * Every write here goes through the new MatterAssignmentService::
 * add()/remove() — never a raw MatterAssignment::create()/update()
 * call, mirroring OpenMatterAction's own "hand off to the service,
 * surface its own RuntimeException" discipline.
 *
 * A separate, dedicated tab rather than folding CRUD into ViewMatter's
 * existing read-only "Team" infolist section: that section stays a
 * quick, glanceable summary of the active team (unchanged by this
 * mission), while this RelationManager is the correct shape for the
 * actual list-with-row-actions + add-record table UI a Filament
 * infolist section cannot cleanly host — the same reasoning
 * ContactsRelationManager's own docblock gives for why Contacts get a
 * RelationManager tab rather than being folded into an infolist
 * section.
 *
 * Gate: MatterAccessPolicyService::canAccessMatter() (the real
 * per-record boundary every Matter tab checks) for visibility;
 * mutation (add/remove) additionally requires
 * MatterCreationAccessPolicyService::canOpenMatter()'s role ceiling
 * (FirmOwner/Attorney/Paralegal/LegalAssistant) — deliberately reused
 * rather than inventing a fourth *AccessPolicyService method, matching
 * this mission's "prefer reuse over a new parallel policy class"
 * guidance; staffing a matter is the same "consequential matter
 * lifecycle action" tier as opening/closing one.
 */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'matterAssignments';

    protected static ?string $title = 'Assignments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord);
    }

    private function canManage(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(MatterCreationAccessPolicyService::class)->canOpenMatter($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('User')->placeholder('—'),
                TextColumn::make('role')->placeholder('—'),
                IconColumn::make('is_lead')->label('Lead')->boolean(),
                TextColumn::make('assigned_at')->dateTime()->placeholder('—'),
                TextColumn::make('removed_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (MatterAssignment $record): string => $record->removed_at === null ? 'Active' : 'Removed')
                    ->color(fn (MatterAssignment $record): string => $record->removed_at === null ? 'success' : 'gray'),
            ])
            ->defaultSort('removed_at', 'asc')
            ->headerActions([
                Action::make('addAssignment')
                    ->label('Add Assignment')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('primary')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->options(function (): array {
                                $firmUser = Auth::user()?->activeFirmUser();

                                if ($firmUser === null) {
                                    return [];
                                }

                                /** @var Matter $matter */
                                $matter = $this->getOwnerRecord();

                                return app(TenantContextService::class)->runWithFirmContext(
                                    (int) $matter->firm_id,
                                    fn (): array => FirmUser::query()
                                        ->with('user')
                                        ->where('firm_id', $matter->firm_id)
                                        ->where('status', FirmUserStatus::Active)
                                        ->get()
                                        ->mapWithKeys(fn (FirmUser $fu): array => [$fu->user_id => $fu->user?->name ?? "User #{$fu->user_id}"])
                                        ->all(),
                                );
                            })
                            ->searchable()
                            ->required(),
                        TextInput::make('role')
                            ->label('Role')
                            ->maxLength(255)
                            ->helperText('Freeform — e.g. "paralegal", "second chair".'),
                        Toggle::make('is_lead')
                            ->label('Lead on this matter'),
                    ])
                    ->action(function (array $data): void {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null || ! $this->canManage()) {
                            Notification::make()->title('Not permitted')->body('Your role may not staff matters.')->danger()->send();

                            return;
                        }

                        /** @var Matter $matter */
                        $matter = $this->getOwnerRecord();

                        try {
                            app(TenantContextService::class)->runWithFirmContext(
                                (int) $matter->firm_id,
                                function () use ($matter, $data, $firmUser): void {
                                    $user = FirmUser::query()
                                        ->where('firm_id', $matter->firm_id)
                                        ->where('user_id', $data['user_id'])
                                        ->firstOrFail()
                                        ->user;

                                    app(MatterAssignmentService::class)->add(
                                        $matter,
                                        $user,
                                        (string) ($data['role'] ?? ''),
                                        (bool) ($data['is_lead'] ?? false),
                                        $firmUser,
                                    );
                                },
                            );

                            Notification::make()->title('Assignment added')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not add assignment')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('removeAssignment')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MatterAssignment $record): bool => $record->removed_at === null && $this->canManage())
                    ->action(function (MatterAssignment $record): void {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null || ! $this->canManage()) {
                            Notification::make()->title('Not permitted')->danger()->send();

                            return;
                        }

                        /** @var Matter $matter */
                        $matter = $this->getOwnerRecord();

                        try {
                            app(TenantContextService::class)->runWithFirmContext(
                                (int) $matter->firm_id,
                                fn () => app(MatterAssignmentService::class)->remove($matter, $record, $firmUser),
                            );

                            Notification::make()->title('Assignment removed')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not remove assignment')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }
}
