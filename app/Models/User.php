<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\FirmUserStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Services\FirmUser2faPolicyService;
use App\Services\LoginPolicyService;
use App\Services\TenantContextService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasPublicUuid;

    public function firmUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\FirmUser::class);
    }

    public function firms(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Firm::class, 'firm_users')
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
     * Resolves this user's own active FirmUser membership as a
     * bootstrap read — firm_users has permanent FORCE ROW LEVEL
     * SECURITY, so this row is otherwise invisible before any firm
     * context is known (see the
     * 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy
     * migration). Activates ONLY the narrow app.current_user_id
     * self-lookup session setting for the duration of this one query,
     * never app.current_firm_id/PHP-memory firm context.
     */
    public function activeFirmUser(): ?\App\Models\FirmUser
    {
        return (new TenantContextService())->withUserContext(
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

        if (! (new LoginPolicyService())->canAttemptFirmLogin($this, $firmUser->firm)) {
            return false;
        }

        $twoFactorPolicy = new FirmUser2faPolicyService();

        if ($twoFactorPolicy->isRequiredForFirmUser($firmUser) && ! $twoFactorPolicy->isCompliant($firmUser)) {
            return false;
        }

        return true;
    }
}
