<?php

namespace App\Filament\MultiFactor\WebAuthn\Actions;

use App\Filament\MultiFactor\WebAuthn\WebAuthnAuthentication;
use App\Models\PlatformAdmin;
use App\Services\WebAuthn\WebAuthnCeremonyService;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use InvalidArgumentException;
use Webauthn\Exception\WebauthnException;

/**
 * RegisterWebAuthnCredentialAction — Mission 1B (Extreme Security
 * Hardening). Mirrors SetUpAppAuthenticationAction's own shape
 * (Filament's stock TOTP enrollment action) as closely as the
 * ceremony's own requirements allow: options are generated
 * server-side in mountUsing() (a real WebAuthnCeremonyService call —
 * no client input needed to build them) and round-tripped through the
 * mounted action's own encrypted arguments, exactly like TOTP's secret
 * generation does — never through a separate AJAX endpoint or the
 * session.
 *
 * The browser-side WebAuthn ceremony itself
 * (resources/views/filament/multi-factor/webauthn/register.blade.php)
 * could not be exercised against a real authenticator in this
 * environment (no browser automation available here) — it follows the
 * same `$wire.set('mountedActions.0.data.<field>', ...)` binding this
 * codebase's own tests already rely on
 * (LivewireUpdateRouteTenantContextFixTest::dataUpdates()) to write
 * the ceremony result into this action's own hidden field, but has not
 * been verified end-to-end against a real security key. The
 * cryptographic verification this hidden field feeds
 * (WebAuthnCeremonyService::verifyRegistration()) IS fully verified —
 * see WebAuthnCeremonyServiceTest, which drives it with a real EC
 * key/signature.
 */
class RegisterWebAuthnCredentialAction
{
    public static function make(WebAuthnAuthentication $provider): Action
    {
        return Action::make('registerWebAuthnCredential')
            ->label(__('Register a security key or passkey'))
            ->color('primary')
            ->icon(Heroicon::Key)
            ->mountUsing(function (HasActions $livewire, Schema $schema): void {
                $schema->fill();

                /** @var PlatformAdmin $admin */
                $admin = Filament::auth()->user();

                [, $optionsJson] = app(WebAuthnCeremonyService::class)->creationOptionsFor($admin);

                $livewire->mergeMountedActionArguments([
                    'encrypted' => encrypt(['optionsJson' => $optionsJson]),
                ]);
            })
            ->schema(fn (Action $action): array => [
                TextInput::make('label')
                    ->label(__('Name this key'))
                    ->placeholder(__('e.g. "YubiKey — desk drawer"'))
                    ->required()
                    ->maxLength(100),
                View::make('filament.multi-factor.webauthn.register')
                    ->viewData(['optionsJson' => Js::from(self::decryptedOptionsJson($action))]),
                Hidden::make('credentialResponseJson'),
            ])
            ->action(function (array $data, array $arguments): void {
                /** @var PlatformAdmin $admin */
                $admin = Filament::auth()->user();

                $decrypted = decrypt($arguments['encrypted']);

                if (blank($data['credentialResponseJson'] ?? null)) {
                    Notification::make()
                        ->title(__('The security key ceremony did not complete. Please try again.'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    app(WebAuthnCeremonyService::class)->verifyRegistration(
                        $data['credentialResponseJson'],
                        $decrypted['optionsJson'],
                        $admin,
                        $data['label'],
                    );
                } catch (InvalidArgumentException|WebauthnException $e) {
                    Notification::make()
                        ->title(__('This security key could not be verified.'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Security key registered.'))
                    ->success()
                    ->send();
            });
    }

    private static function decryptedOptionsJson(Action $action): string
    {
        $encrypted = $action->getArguments()['encrypted'] ?? null;

        if ($encrypted === null) {
            return '{}';
        }

        return decrypt($encrypted)['optionsJson'] ?? '{}';
    }
}
