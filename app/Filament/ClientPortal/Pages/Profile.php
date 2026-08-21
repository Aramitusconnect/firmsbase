<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Pages;

use App\Models\ClientPortalUser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Profile (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.7. A minimal, self-service page: email change + password
 * change only, plus a read-only `last_login_at` display. Modeled
 * directly on `App\Filament\Firm\Pages\FirmSettingsPage`'s own
 * established "InteractsWithSchemas + a single `save()` Action wired to
 * `EmbeddedSchema::make('form')`" shape — auto-discovered from
 * `app/Filament/ClientPortal/Pages` (ClientPortalPanelProvider's own
 * `discoverPages()` call, unedited), no separate panel registration
 * needed.
 *
 * Password rule (`Password::default()`) mirrors exactly what
 * `App\Filament\ClientPortal\Pages\Auth\ResetPassword`'s own base class
 * (`Filament\Auth\Pages\PasswordReset\ResetPassword`) uses for its own
 * password field — same complexity requirement, both places. Hashing
 * uses `Hash::make()` before assignment, mirroring
 * `ClientPortalService::activate()`'s own established convention
 * (safe alongside `ClientPortalUser`'s `'password' => 'hashed'` cast,
 * which only re-hashes a value `Hash::isHashed()` reports as not
 * already hashed).
 *
 * `is_active` is NEVER exposed as a field here — not in `form()`, not
 * read by `save()` — a client cannot re-activate or deactivate their
 * own portal account from their own profile page.
 */
class Profile extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $navigationLabel = 'Profile';

    protected static ?string $title = 'Profile';

    protected static ?string $slug = 'profile';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public ?string $lastLoginDisplay = null;

    public static function canAccess(): bool
    {
        return Auth::guard('client')->check();
    }

    public function mount(): void
    {
        $portalUser = $this->currentPortalUser();

        abort_unless($portalUser !== null, 403);

        $this->lastLoginDisplay = $portalUser->last_login_at?->toDayDateTimeString() ?? 'Never';

        $this->form->fill([
            'email' => $portalUser->email,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                Action::make('save')
                    ->label('Save Changes')
                    ->action('save'),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'client_portal_users', column: 'email', ignorable: $this->currentPortalUser()),
                        Text::make(fn (): string => "Last Login: {$this->lastLoginDisplay}"),
                    ]),
                Section::make('Change Password')
                    ->description('Leave both fields blank to keep your current password.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('password')
                            ->label('New Password')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->autocomplete('new-password')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->same('passwordConfirmation'),
                        TextInput::make('passwordConfirmation')
                            ->label('Confirm New Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->dehydrated(false)
                            ->requiredWith('password'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $portalUser = $this->currentPortalUser();

        abort_unless($portalUser !== null, 403);

        $state = $this->form->getState();

        $updates = [
            'email' => $state['email'],
        ];

        if (filled($state['password'] ?? null)) {
            $updates['password'] = Hash::make($state['password']);
        }

        $portalUser->update($updates);

        $this->lastLoginDisplay = $portalUser->fresh()->last_login_at?->toDayDateTimeString() ?? 'Never';

        $this->form->fill([
            'email' => $portalUser->fresh()->email,
        ]);

        Notification::make()->title('Profile updated')->success()->send();
    }

    private function currentPortalUser(): ?ClientPortalUser
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        return $portalUser;
    }
}
