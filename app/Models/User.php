<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\ConsentChannel;
use App\Enums\FirmUserStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Services\FirmUser2faPolicyService;
use App\Services\LoginPolicyService;
use App\Services\OutboundMailCorrelationService;
use App\Services\PlatformNotificationCorrelationService;
use App\Services\TenantContextService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
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
     * SES event consumer (feature/ses-event-consumer) — resolves this
     * user's own firm via the existing activeFirmUser() self-lookup
     * (never from the email address itself) and, only when that
     * succeeds, routes the send through OutboundMailCorrelationService
     * so a later bounce/complaint can be resolved back to the correct
     * firm. This must never block a password reset from being
     * attempted, so a User with no resolvable firm still gets one —
     * but never uncorrelated and untracked (post-578ee98 audit finding
     * H1): PlatformNotificationCorrelationService gives it a
     * tenant-agnostic correlation instead, so a later bounce/complaint
     * can still be recorded, and a permanently-bad address stops being
     * retried. Every User is expected to resolve an active firm — one
     * that doesn't is itself anomalous (mid-deactivation is the only
     * legitimate case), so this branch also emits an operational alert
     * distinct from ClientPortalUser's own identical fallback, which
     * has more benign causes (a detached Client record).
     *
     * The platform-correlation subsystem is layered on top of, never a
     * precondition for, the actual send: a misconfigured/unavailable
     * PlatformNotificationCorrelationService (e.g. a missing HMAC key)
     * must never silently swallow a password reset — this is exactly
     * the "must never block a password reset" guarantee this docblock
     * already stated, which an earlier version of this fix violated by
     * letting isRecipientSuppressed()/correlate()'s own exceptions
     * propagate up into FirmProvisioningService::dispatchOwnerInvitation()'s
     * broad catch(Throwable), silently marking a genuine invitation as
     * failed before notify() was ever reached.
     */
    public function sendPasswordResetNotification($token): void
    {
        $firm = $this->activeFirmUser()?->firm;

        if ($firm === null) {
            Log::alert('user_password_reset_no_firm_correlation', [
                'user_id' => $this->id,
            ]);

            $sent = false;

            try {
                $platformCorrelation = app(PlatformNotificationCorrelationService::class);

                if ($platformCorrelation->isRecipientSuppressed($this->email)) {
                    Log::warning('user_password_reset_skipped_platform_suppressed', [
                        'user_id' => $this->id,
                    ]);

                    return;
                }

                $platformCorrelation->correlate(
                    static::class,
                    $this->id,
                    'user_password_reset',
                    $this->email,
                    function (string $correlationId) use ($token, &$sent): void {
                        $this->notify((new FirmOwnerInvitationNotification($token))->withCorrelationId($correlationId));
                        $sent = true;
                    },
                );

                return;
            } catch (\Throwable $e) {
                Log::error('user_password_reset_platform_correlation_unavailable', [
                    'user_id' => $this->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                // Only send here if the closure above never actually
                // ran notify() — the failure was in the platform-
                // correlation bookkeeping BEFORE/AFTER the real send, not
                // in the send itself, so falling back must never
                // re-send and duplicate a password reset the recipient
                // already received.
                if (! $sent) {
                    $this->notify(new FirmOwnerInvitationNotification($token));
                }

                return;
            }
        }

        app(OutboundMailCorrelationService::class)->correlate(
            $firm,
            ConsentChannel::Email,
            $this->email,
            function (string $correlationId) use ($token): void {
                $this->notify((new FirmOwnerInvitationNotification($token))->withCorrelationId($correlationId));
            },
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
}
