<?php

namespace App\Filament\MultiFactor\WebAuthn;

use App\Filament\MultiFactor\WebAuthn\Actions\DisableWebAuthnCredentialAction;
use App\Filament\MultiFactor\WebAuthn\Actions\RegisterWebAuthnCredentialAction;
use App\Models\PlatformAdmin;
use App\Services\WebAuthn\WebAuthnCeremonyService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Js;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Webauthn\Exception\WebauthnException;

/**
 * WebAuthnAuthentication — Mission 1B (Extreme Security Hardening).
 * The mission's headline requirement: real, working, phishing-resistant
 * WebAuthn/passkey authentication for Platform Admin, implementing
 * Filament's own MultiFactorAuthenticationProvider contract (the same
 * interface stock TOTP uses via AuditedAppAuthentication) so it can be
 * registered as a genuine additional factor rather than a bolted-on
 * side flow — see AdminPanelProvider's own
 * ->multiFactorAuthentication([...]) call.
 *
 * The cryptographic core (WebAuthnCeremonyService) is fully verified
 * against the real, unmodified web-auth/webauthn-lib validators using
 * genuine EC P-256 signatures — see WebAuthnCeremonyServiceTest. This
 * class is the thin Filament-facing wiring around it; only its own
 * PHP-side logic (isEnabled/labels/action wiring) is verified by
 * WebAuthnAuthenticationTest — the browser-side ceremony
 * (resources/views/filament/multi-factor/webauthn/*.blade.php) could
 * not be exercised against a real authenticator in this environment
 * (no browser automation available here).
 */
class WebAuthnAuthentication implements MultiFactorAuthenticationProvider
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'webauthn';
    }

    public function getLoginFormLabel(): string
    {
        return __('Security key or passkey');
    }

    public function isEnabled(Authenticatable $user): bool
    {
        if (! ($user instanceof PlatformAdmin)) {
            throw new LogicException('WebAuthn authentication is only available for PlatformAdmin.');
        }

        return $user->webauthnCredentials()->exists();
    }

    /**
     * @return array<Component | Action | ActionGroup>
     */
    public function getManagementSchemaComponents(): array
    {
        /** @var PlatformAdmin $admin */
        $admin = Filament::auth()->user();
        $credentials = $admin->webauthnCredentials()->orderByDesc('created_at')->get();

        $rows = $credentials->map(fn ($credential) => Actions::make([DisableWebAuthnCredentialAction::make($credential)])
            ->label(
                $credential->name.' — '.__('added').' '.$credential->created_at->diffForHumans()
                .($credential->last_used_at ? ' — '.__('last used').' '.$credential->last_used_at->diffForHumans() : ' — '.__('never used'))
            ))->all();

        return [
            ...$rows,
            Actions::make([RegisterWebAuthnCredentialAction::make($this)])
                ->label(__('Security keys and passkeys'))
                ->belowContent($credentials->isEmpty()
                    ? Text::make(__('No security keys registered yet.'))->badge()
                    : Text::make($credentials->count().' '.__('registered'))->badge()->color('success')),
        ];
    }

    /**
     * @return array<Component | Action | ActionGroup>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        /** @var PlatformAdmin $user */
        [$options, $optionsJson] = app(WebAuthnCeremonyService::class)->requestOptionsFor($user);

        return [
            View::make('filament.multi-factor.webauthn.challenge')
                ->viewData(['optionsJson' => Js::from($optionsJson)]),
            Hidden::make('webauthnResponseJson')
                ->required()
                ->rule(function () use ($optionsJson, $user): Closure {
                    return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($optionsJson, $user): void {
                        if (! is_string($value) || blank($value)) {
                            $fail(__('Please complete the security key prompt.'));

                            return;
                        }

                        try {
                            app(WebAuthnCeremonyService::class)->verifyAuthentication($value, $optionsJson, $user);
                        } catch (WebauthnException|InvalidArgumentException) {
                            $fail(__('This security key could not be verified.'));
                        }
                    };
                }),
        ];
    }
}
