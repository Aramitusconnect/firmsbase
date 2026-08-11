<?php

namespace App\Filament\MultiFactor\WebAuthn\Actions;

use App\Models\PlatformAdmin;
use App\Models\WebauthnCredential;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * DisableWebAuthnCredentialAction — Mission 1B (Extreme Security
 * Hardening), section 7 ("adding/removing an authenticator requires
 * recent high-assurance authentication, not just a stolen session").
 * Mirrors this exact codebase's own EditProfile currentPassword
 * requirement — the simplest, already-established "recent auth" check
 * this project uses for sensitive account changes — rather than
 * inventing a separate mechanism.
 */
class DisableWebAuthnCredentialAction
{
    public static function make(WebauthnCredential $credential): Action
    {
        return Action::make('disableWebAuthnCredential-'.$credential->id)
            ->label(__('Remove'))
            ->color('danger')
            ->icon(Heroicon::Trash)
            ->requiresConfirmation()
            ->schema([
                TextInput::make('currentPassword')
                    ->label(__('Current password'))
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->required()
                    ->rule(function (): Closure {
                        return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                            /** @var PlatformAdmin $admin */
                            $admin = Filament::auth()->user();

                            if (! is_string($value) || ! Hash::check($value, $admin->password)) {
                                $fail(__('The password is incorrect.'));
                            }
                        };
                    }),
            ])
            ->action(function () use ($credential): void {
                $credential->delete();

                Notification::make()
                    ->title(__('Security key removed.'))
                    ->success()
                    ->send();
            });
    }
}
