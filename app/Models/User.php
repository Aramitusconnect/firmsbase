<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\ConsentChannel;
use App\Enums\FirmUserStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Services\CorrelatedPasswordResetSenderService;
use App\Services\FirmUser2faPolicyService;
use App\Services\LoginPolicyService;
use App\Services\TenantContextService;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicUuid, Notifiable;

    public function firmUsers(): HasMany
    {
        return $this->hasMany(FirmUser::class);
    }

    public function firms(): BelongsToMany
    {
        return $this->belongsToMany(Firm::class, 'firm_users')
            ->withPivot(['role', 'status', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Overrides `CanResetPassword`'s default notification, which builds
     * its reset URL via `route('password.reset', ...)` — a route this
     * app does not have (see FirmOwnerInvitationNotification's own
     * docblock). Sent for every password reset, not only a brand-new
     * owner's first-time setup — mirrors ClientPortalUser's own
     * identical, non-special-cased override.
     *
     * Post-9722e88 audit remediation: delegates entirely to
     * CorrelatedPasswordResetSenderService and discards its typed
     * result. This is deliberate, not an oversight — this method's
     * signature is fixed by Laravel's `CanResetPassword` contract
     * (void), and it is reached by the PUBLIC, anti-enumeration-
     * sensitive "forgot password" flow (`Illuminate\Auth\Passwords\
     * PasswordBroker::sendResetLink()`, called with no custom
     * callback) — that flow's HTTP response must never differ based on
     * whether the internal send actually succeeded, or a failure here
     * would become an observable side channel distinguishing "this
     * email belongs to an account with an internal error" from "this
     * email doesn't exist". The service itself never throws and never
     * falls back to an uncorrelated send on any failure; it only
     * returns Sent, Suppressed, CorrelationFailed, or TransportFailed,
     * each already logged at the appropriate severity internally.
     *
     * FirmProvisioningService::dispatchOwnerInvitation()/
     * resendInvitation() are NOT anti-enumeration-sensitive (an
     * authenticated platform admin already knows the firm/owner
     * exists) — they call CorrelatedPasswordResetSenderService
     * directly via sendResetLink()'s own `$callback` parameter instead
     * of going through this method, passing the Firm they already
     * have in hand rather than re-resolving it, and inspect the typed
     * result to decide invitation success/failure.
     */
    public function sendPasswordResetNotification($token): void
    {
        $firm = $this->activeFirmUser()?->firm;

        if ($firm !== null) {
            app(CorrelatedPasswordResetSenderService::class)->sendForFirm(
                $this,
                $firm,
                ConsentChannel::Email,
                $this->email,
                fn (string $correlationId) => (new FirmOwnerInvitationNotification($token))->withCorrelationId($correlationId),
            );

            return;
        }

        app(CorrelatedPasswordResetSenderService::class)->sendForUnresolvedFirm(
            $this,
            'user_password_reset',
            $this->email,
            fn (string $correlationId) => (new FirmOwnerInvitationNotification($token))->withCorrelationId($correlationId),
        );
    }

    /**
     * Resolves this user's own active FirmUser membership as a
     * bootstrap read — firm_users has permanent FORCE ROW LEVEL
     * SECURITY, so this row is otherwise invisible before any firm
     * context is known (see the
     * 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy
     * migration). Activates ONLY the narrow app.current_user_id
     * self-lookup session setting for the duration of this one query,
     * never app.current_firm_id/PHP-memory firm context.
     */
    public function activeFirmUser(): ?FirmUser
    {
        return (new TenantContextService)->withUserContext(
            $this->id,
            fn () => $this->firmUsers()->where('status', FirmUserStatus::Active->value)->first(),
        );
    }

    /**
     * The firm-facing panel gate — checked by Filament on every panel
     * request, not just at login (see Filament\Http\Middleware\Authenticate).
     * Deliberately routes through the existing LoginPolicyService and
     * FirmUser2faPolicyService wrapper services rather than re-deriving
     * their checks here, so this gate can never silently drift from the
     * policy those services already define:
     *   - the account itself must be active;
     *   - the user must hold at least one ACTIVE FirmUser membership,
     *     and LoginPolicyService::canAttemptFirmLogin() must approve
     *     that specific firm;
     *   - if that firm requires 2FA (FirmUser2faPolicyService), the
     *     user must already be confirmed-compliant — there is no
     *     in-panel 2FA setup flow to complete it after the fact yet.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $firmUser = $this->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! (new LoginPolicyService)->canAttemptFirmLogin($this, $firmUser->firm)) {
            return false;
        }

        $twoFactorPolicy = new FirmUser2faPolicyService;

        // Section 39A-3L, Checkpoint 18 - firm_settings is FORCE-RLS
        // protected as of this checkpoint, and no per-request middleware
        // establishes tenant context before canAccessPanel() runs. Both
        // isRequiredForFirmUser() and isCompliant() (which internally
        // re-calls isRequiredForFirmUser()) read $firm->firmSettings, so
        // the entire decision is wrapped in one whole-call context here
        // rather than wrapping only one of the two calls - an unwrapped
        // second read would still fail open (firmSettings resolves to
        // null, isRequiredForFirm() returns false) for a firm configured
        // with firm_user_2fa_mode = Required.
        $twoFactorBlocksAccess = (new TenantContextService)->runWithFirmContext(
            $firmUser->firm_id,
            fn () => $twoFactorPolicy->isRequiredForFirmUser($firmUser) && ! $twoFactorPolicy->isCompliant($firmUser)
        );

        if ($twoFactorBlocksAccess) {
            return false;
        }

        return true;
    }

    /**
     * Firm Feature Manifest §11/§39B — Filament\Auth\MultiFactor\App\
     * Contracts\HasAppAuthentication. Mirrors PlatformAdmin's own
     * identical implementation exactly (same contract, same shape) —
     * reuses the two_factor_secret/two_factor_recovery_codes/
     * two_factor_confirmed_at columns that already exist on `users`
     * (added in 2026_07_04_200001_add_identity_fields_to_users_table,
     * already cast on this model above) and that
     * FirmUser2faPolicyService::isCompliant() already reads as its sole
     * source of "has confirmed 2FA" truth. No new column, no new
     * migration — this is wiring an existing, already-relied-upon data
     * shape onto Filament's own MFA provider contract for the first
     * time, not introducing new state.
     *
     * Required for FirmPanelProvider's self-service 2FA enrollment
     * (Filament\Auth\MultiFactor\App\AppAuthentication) to function at
     * all: AppAuthentication::isEnabled() unconditionally throws a
     * LogicException if the acting user does not implement this
     * interface, and Filament's own Login page calls isEnabled() for
     * EVERY login attempt against a panel with any multi-factor
     * provider configured — regardless of that panel's isRequired
     * setting. Implementing this interface is therefore a
     * precondition for ANY firm user (enrolled or not) to be able to
     * log in at all once FirmPanelProvider registers a provider, not
     * merely a precondition for the enrollment feature itself.
     */
    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => filled($secret) ? now() : null,
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery
     * — see getAppAuthenticationSecret()'s docblock above for the full
     * reasoning; identical mirror of PlatformAdmin's own implementation.
     *
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();
    }
}
