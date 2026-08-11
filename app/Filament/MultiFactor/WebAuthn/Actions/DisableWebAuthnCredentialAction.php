<?php

namespace App\Filament\MultiFactor\WebAuthn\Actions;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Models\WebauthnCredential;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * DisableWebAuthnCredentialAction — Mission 1B (Extreme Security
 * Hardening), section 7 ("adding/removing an authenticator requires
 * recent high-assurance authentication, not just a stolen session").
 * Wired onto the canonical StepUpAuthentication helper (section 9)
 * rather than a hand-rolled password check, so this composes with
 * every other protected operation's step-up window instead of
 * maintaining its own.
 */
class DisableWebAuthnCredentialAction
{
    public static function make(WebauthnCredential $credential): Action
    {
        $action = Action::make('disableWebAuthnCredential-'.$credential->id)
            ->label(__('Remove'))
            ->color('danger')
            ->icon(Heroicon::Trash)
            ->action(function () use ($credential): void {
                $credential->delete();

                Notification::make()
                    ->title(__('Security key removed.'))
                    ->success()
                    ->send();
            });

        return StepUpAuthentication::protect($action, 'platform_admin');
    }
}
