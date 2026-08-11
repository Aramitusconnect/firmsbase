<?php

namespace App\Filament\Support\StepUp;

use App\Services\Security\StepUpAuthenticationService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * StepUpAuthentication — Mission 1B (Extreme Security Hardening),
 * section 9's canonical reusable step-up architecture. Any Filament
 * Action protecting a sensitive operation (change MFA, remove a
 * WebAuthn credential, regenerate recovery codes, add/remove a Firm
 * Owner, change security settings, impersonate, export sensitive
 * data, rotate API keys, ...) calls
 * StepUpAuthentication::protect($action, $guard) once, instead of
 * hand-rolling its own password field + Hash::check (the pattern
 * DisableWebAuthnCredentialAction used before this file existed, and
 * is refactored onto this in the same commit).
 *
 * Behavior: if this session already has a fresh verification (within
 * $withinMinutes) for the given guard, the password field is omitted
 * and the action proceeds on confirm alone — the entire point of a
 * reusable step-up window is that a user who just proved possession of
 * their password for one protected operation isn't re-prompted for
 * every subsequent one within a short window. A session with no fresh
 * verification (including one that is authenticated but stolen) is
 * always forced through the password check first.
 */
class StepUpAuthentication
{
    public static function protect(Action $action, string $guard, int $withinMinutes = 5): Action
    {
        return $action
            ->requiresConfirmation()
            ->schema(fn () => self::schemaFor($guard, $withinMinutes));
    }

    /**
     * For an Action that already has its own domain-specific schema
     * (e.g. EnterSupportAccessSessionAction's request-selection field)
     * — appends the step-up password field to that existing schema
     * instead of replacing it. $baseSchema may be a plain array or a
     * Closure returning one, matching what Action::schema() itself
     * already accepts.
     */
    public static function mergeInto(Action $action, array|Closure $baseSchema, string $guard, int $withinMinutes = 5): Action
    {
        return $action
            ->requiresConfirmation()
            ->schema(function () use ($baseSchema, $guard, $withinMinutes): array {
                $base = $baseSchema instanceof Closure ? $baseSchema() : $baseSchema;

                return [...$base, ...self::schemaFor($guard, $withinMinutes)];
            });
    }

    /**
     * @return array<int, TextInput>
     */
    public static function schemaFor(string $guard, int $withinMinutes = 5): array
    {
        return self::isRecentlyVerified($guard, $withinMinutes)
            ? []
            : [self::passwordField($guard)];
    }

    public static function isRecentlyVerified(string $guard, int $withinMinutes = 5): bool
    {
        return app(StepUpAuthenticationService::class)->hasRecentVerification($guard, $withinMinutes);
    }

    public static function passwordField(string $guard): TextInput
    {
        return TextInput::make('stepUpCurrentPassword')
            ->label(__('Confirm your password'))
            ->password()
            ->revealable(Filament::arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required()
            ->dehydrated(false)
            ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($guard): void {
                self::verifyAndMark($guard, is_string($value) ? $value : null, $fail);
            });
    }

    /**
     * The rule's actual logic, extracted so it can be exercised
     * directly in tests without going through Filament's schema/
     * validation plumbing.
     */
    public static function verifyAndMark(string $guard, ?string $password, Closure $fail): void
    {
        $user = Auth::guard($guard)->user();

        if ($user === null || $password === null || ! Hash::check($password, $user->getAuthPassword())) {
            $fail(__('The password is incorrect.'));

            return;
        }

        app(StepUpAuthenticationService::class)->markVerified($guard);
    }
}
