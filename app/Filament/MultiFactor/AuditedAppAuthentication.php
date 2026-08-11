<?php

declare(strict_types=1);

namespace App\Filament\MultiFactor;

use App\Models\PlatformAdmin;
use App\Notifications\PlatformAdminRecoveryCodeUsedNotification;
use App\Services\PlatformAdminAuditEventRecorder;
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
 * AuditedAppAuthentication — FirmsVault Admin Control Center MFA design
 * proposal §7. Subclasses Filament's own
 * Filament\Auth\MultiFactor\App\AppAuthentication purely to hook audit
 * recording onto the vendor-owned points where MFA state actually
 * changes or is checked — every OTHER behavior (secret/recovery-code
 * generation, QR rendering, rate limiting, code-reuse prevention, the
 * management-schema actions themselves) is inherited unchanged.
 *
 * Coupling risk (explicitly flagged, not silently accepted — design
 * proposal uncertainty #3): this subclass overrides
 * getChallengeFormComponents(), whose vendor implementation is NOT
 * marked `final` and has a stable signature today (confirmed by reading
 * vendor/filament/filament/src/Auth/MultiFactor/App/AppAuthentication.php
 * directly, not merely trusting a summary), but a future Filament
 * upgrade that changes that method's internals would silently stop
 * this subclass's override from tracking them — the override is a
 * near-verbatim copy of the vendor method body, not a thin wrapper,
 * because the vendor method builds its validation rule Closures inline
 * and there is no narrower extension point (no event, no hook) to
 * intercept just the pass/fail outcome of verifyCode()/
 * verifyRecoveryCode() during the LOGIN CHALLENGE specifically. This is
 * the one point in the whole enroll/verify/disable lifecycle where the
 * acting PlatformAdmin is NOT available via Filament::auth()->user()
 * (the user is mid-login, not yet Auth::login()'d) — it is only
 * available as this method's own $user parameter, which is exactly why
 * overriding this method (rather than verifyCode()/verifyRecoveryCode()
 * themselves) is the correct hook point for challenge-time auditing.
 *
 * saveSecret()/saveRecoveryCodes(), by contrast, both receive the
 * target user directly as a parameter in every real call site
 * (self-service enroll/disable/regenerate, PlatformAdminMfaResetService,
 * and the emergency Artisan command all pass the concrete PlatformAdmin
 * through Filament's own AppAuthentication API) — no such ambiguity,
 * so those two overrides are thin call-parent-then-audit wrappers.
 *
 * Event catalog (all category 'platform_admin_mfa', all written via
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() — never
 * firm-scoped, since a PlatformAdmin's own MFA state has nothing to do
 * with any one firm):
 *  - mfa_enrolled              saveSecret() with a non-null secret
 *  - mfa_disabled              saveSecret() with a null secret
 *  - mfa_recovery_codes_generated  saveRecoveryCodes() with non-null codes
 *  - mfa_recovery_codes_cleared    saveRecoveryCodes() with null codes
 *  - mfa_challenge_succeeded   login-time TOTP code verified
 *  - mfa_challenge_failed      login-time TOTP code rejected
 *  - mfa_recovery_code_used    login-time recovery code verified
 *  - mfa_recovery_code_verification_failed  login-time recovery code rejected
 * A ninth event type, mfa_reset_by_admin, is recorded by
 * PlatformAdminMfaResetService itself (not here) — that event's actor
 * is the ACTING SuperAdmin performing the reset, not the target, which
 * this class has no way to know (this class only ever sees the target
 * admin passed to saveSecret()/saveRecoveryCodes()).
 *
 * Deliberately NOT audited here (scope decision, not an oversight):
 * verifyCode()/verifyRecoveryCode() calls made OUTSIDE the login
 * challenge (i.e. during self-service enroll/disable/regenerate
 * validation on the profile page) are not separately logged — the
 * OUTCOME of those flows is already fully captured by the
 * saveSecret()/saveRecoveryCodes() hooks above (an enroll attempt with
 * a wrong code simply never reaches saveSecret() at all), and Filament's
 * own rate limiting on those actions already provides brute-force
 * defense. Adding a distinct audit row for every keystroke-level
 * validation attempt on an already-authenticated, already-rate-limited
 * self-service action was judged to add audit-log noise without a
 * matching security signal.
 */
class AuditedAppAuthentication extends AppAuthentication
{
    private const CATEGORY = 'platform_admin_mfa';

    public function __construct(
        Google2FA $google2FA,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder,
    ) {
        parent::__construct($google2FA);
    }

    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        $this->recordIfPlatformAdmin($user, filled($secret) ? 'mfa_enrolled' : 'mfa_disabled');
    }

    /**
     * @param  array<string> | null  $codes
     */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        parent::saveRecoveryCodes($user, $codes);

        $this->recordIfPlatformAdmin($user, is_array($codes) ? 'mfa_recovery_codes_generated' : 'mfa_recovery_codes_cleared');
    }

    /**
     * Near-verbatim override of AppAuthentication::getChallengeFormComponents()
     * — see this class's own docblock for why this method (rather than
     * verifyCode()/verifyRecoveryCode() directly) is the correct audit
     * hook point for login-challenge attempts specifically.
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
                            $this->recordIfPlatformAdmin($user, 'mfa_challenge_succeeded');

                            return;
                        }

                        $this->recordIfPlatformAdmin($user, 'mfa_challenge_failed');

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
                            $this->recordIfPlatformAdmin($user, 'mfa_recovery_code_used');
                            $this->notifyIfPlatformAdmin($user);

                            return;
                        }

                        $this->recordIfPlatformAdmin($user, 'mfa_recovery_code_verification_failed');

                        $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.recovery_code.messages.invalid'));
                    };
                })
                ->visible(fn (Get $get): bool => $isRecoverable && $get('useRecoveryCode'))
                ->live(onBlur: true),
        ];
    }

    private function recordIfPlatformAdmin(mixed $user, string $eventType): void
    {
        if (! $user instanceof PlatformAdmin) {
            // Never a live path today (PlatformAdmin is the only model
            // wired to this provider, via AdminPanelProvider), but this
            // class must not assume that forever — a non-PlatformAdmin
            // actor simply is not audited here rather than raising.
            return;
        }

        $this->auditRecorder->recordPlatformEvent($user, $eventType, self::CATEGORY);
    }

    /**
     * Mission 1B (Extreme Security Hardening), section 8: user
     * notification on recovery-code use — see
     * PlatformAdminRecoveryCodeUsedNotification's own docblock for
     * why this fires on every use, not just suspicious ones.
     */
    private function notifyIfPlatformAdmin(mixed $user): void
    {
        if (! $user instanceof PlatformAdmin) {
            return;
        }

        $user->notify(new PlatformAdminRecoveryCodeUsedNotification);
    }
}
