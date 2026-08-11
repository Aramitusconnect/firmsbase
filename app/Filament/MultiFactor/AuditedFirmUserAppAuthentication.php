<?php

declare(strict_types=1);

namespace App\Filament\MultiFactor;

use App\Models\User;
use App\Services\FirmUserAuditEventRecorder;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Auth\Authenticatable;
use PragmaRX\Google2FAQRCode\Google2FA;
use SensitiveParameter;

/**
 * AuditedFirmUserAppAuthentication — Mission 1C (Security Validation,
 * Activation & Staging Proof), section 19: closes Mission 1B's own
 * audit finding, "firm-user MFA has ZERO audit trail (only
 * PlatformAdmin's AuditedAppAuthentication records events)."
 *
 * A near-exact mirror of App\Filament\MultiFactor\AuditedAppAuthentication
 * (see that class's own docblock for the full reasoning behind each
 * override — identical here, only the actor/event-recorder/category
 * differ) rather than a generalization of it: FirmPanelProvider's own
 * docblock already explains why the Platform-Admin-specific class was
 * deliberately NOT reused for Firm users — its recordIfPlatformAdmin()
 * hook is a silent no-op for anything not instanceof PlatformAdmin,
 * so reusing it here would record nothing while implying (by class
 * name alone) that firm-user MFA events ARE audited. This class exists
 * so that implication becomes true instead.
 *
 * Firm-scoped: every event is written against the acting user's own
 * activeFirmUser()->firm — a firm user's MFA state genuinely belongs
 * to exactly one firm's audit trail, unlike a PlatformAdmin's (global,
 * firm_id always null). A user with no active firm membership at the
 * point one of these hooks fires (should not happen in practice — MFA
 * management requires already being authenticated into a firm) is
 * simply not audited rather than raising, matching this class's
 * general fail-safe-not-fail-loud philosophy for a non-security-
 * critical audit *write* (the actual MFA verification logic itself is
 * entirely inherited, unmodified, from the vendor class).
 *
 * Event catalog (all category 'firm_user_mfa', all written via
 * FirmUserAuditEventRecorder::record()):
 *  - mfa_enrolled / mfa_disabled
 *  - mfa_recovery_codes_generated / mfa_recovery_codes_cleared
 *  - mfa_challenge_succeeded / mfa_challenge_failed
 *  - mfa_recovery_code_used / mfa_recovery_code_verification_failed
 *
 * Deliberately NOT audited here, same scope decision as the Platform
 * Admin class: self-service enroll/disable validation attempts outside
 * the login challenge are not separately logged — the outcome is
 * already fully captured by the saveSecret()/saveRecoveryCodes() hooks.
 */
class AuditedFirmUserAppAuthentication extends AppAuthentication
{
    private const CATEGORY = 'firm_user_mfa';

    public function __construct(
        Google2FA $google2FA,
        private readonly FirmUserAuditEventRecorder $auditRecorder,
    ) {
        parent::__construct($google2FA);
    }

    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        $this->recordIfFirmUser($user, filled($secret) ? 'mfa_enrolled' : 'mfa_disabled');
    }

    /**
     * @param  array<string> | null  $codes
     */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        parent::saveRecoveryCodes($user, $codes);

        $this->recordIfFirmUser($user, is_array($codes) ? 'mfa_recovery_codes_generated' : 'mfa_recovery_codes_cleared');
    }

    /**
     * Near-verbatim override of AppAuthentication::getChallengeFormComponents()
     * — see AuditedAppAuthentication's own docblock for why this method,
     * rather than verifyCode()/verifyRecoveryCode() directly, is the
     * correct audit hook point for login-challenge attempts specifically
     * (the acting user is only available as this method's own $user
     * parameter at this point in the login lifecycle).
     *
     * @param  Authenticatable&HasAppAuthentication&HasAppAuthenticationRecovery  $user
     * @return array<mixed>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $isRecoverable = $this->isRecoverable();

        return [
            OneTimeCodeInput::make('code')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.label'))
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(fn (Set $set) => $set('useRecoveryCode', true))
                    ->visible(fn (): bool => $isRecoverable && (! $get('useRecoveryCode'))))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.code.validation_attribute'))
                ->required(fn (Get $get): bool => (! $isRecoverable) || blank($get('recoveryCode')))
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[SensitiveParameter] $value, Closure $fail) use ($user): void {
                        if (is_string($value) && $this->verifyCode($value, $this->getSecret($user), shouldPreventCodeReuse: true)) {
                            $this->recordIfFirmUser($user, 'mfa_challenge_succeeded');

                            return;
                        }

                        $this->recordIfFirmUser($user, 'mfa_challenge_failed');

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.code.messages.invalid'));
                    };
                }),
            TextInput::make('recoveryCode')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.label'))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.validation_attribute'))
                ->password()
                ->revealable(Filament::arePasswordsRevealable())
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                        if (blank($value)) {
                            return;
                        }

                        if (is_string($value) && $this->verifyRecoveryCode($value, $user)) {
                            $this->recordIfFirmUser($user, 'mfa_recovery_code_used');

                            return;
                        }

                        $this->recordIfFirmUser($user, 'mfa_recovery_code_verification_failed');

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.messages.invalid'));
                    };
                })
                ->visible(fn (Get $get): bool => $isRecoverable && $get('useRecoveryCode'))
                ->live(onBlur: true),
        ];
    }

    private function recordIfFirmUser(mixed $user, string $eventType): void
    {
        if (! $user instanceof User) {
            return;
        }

        $firmUser = $user->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        $this->auditRecorder->record($firmUser->firm, $user, $eventType, self::CATEGORY);
    }
}
