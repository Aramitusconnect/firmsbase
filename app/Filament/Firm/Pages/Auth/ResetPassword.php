<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages\Auth;

use App\Enums\FirmUserStatus;
use App\Models\User;
use App\Services\TenantContextService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Firm-panel override of Filament's own ResetPassword page — needed for
 * exactly one reason: the base page's reset callback (see vendor source)
 * refuses to set a password at all unless
 * `$user->canAccessPanel($panel)` already returns true. For a firm
 * owner completing their FIRST-ever invitation, that can never be true
 * yet — User::canAccessPanel() requires an ACTIVE FirmUser membership
 * (see its own docblock), but the owner's membership starts as
 * FirmUserStatus::Invited and is only flipped to Active by
 * AppServiceProvider::registerFirmOwnerInvitationAcceptance(), a
 * listener on the very `PasswordReset` event the base page's callback
 * refuses to fire in this exact case. That is a real, structural
 * deadlock — the base page's own precondition made it impossible for
 * the one thing that satisfies that precondition to ever happen, so
 * every invited owner's first setup attempt failed with Filament's
 * generic "invalid user" notification, regardless of how many times an
 * invitation was resent.
 *
 * The fix here is narrowly scoped: allow completion when
 * canAccessPanel() fails ONLY because the account has no active
 * membership yet, AND the account is otherwise active and has a
 * genuinely pending (Invited) FirmUser row — never for any other
 * canAccessPanel() failure reason (a deactivated account, 2FA
 * non-compliance, a login-policy block all still refuse exactly as the
 * base page already does, since an already-Active member has no
 * Invited row for this fallback to match). An ordinary "forgot
 * password" reset for an already-active member is entirely unaffected
 * — canAccessPanel() alone already returns true for that case, so the
 * fallback below is never reached.
 */
class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->isResetPasswordRateLimited($this->email)) {
            return null;
        }

        $data = $this->form->getState();

        $data['email'] = $this->email;
        $data['token'] = $this->token;

        $hasPanelAccess = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$hasPanelAccess): void {
                if (
                    ($user instanceof FilamentUser) &&
                    (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) &&
                    (! $this->hasPendingFirmOwnerInvitation($user))
                ) {
                    $hasPanelAccess = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($hasPanelAccess === false) {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()
                ->title(__($status))
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()
            ->title(__($status))
            ->danger()
            ->send();

        return null;
    }

    private function hasPendingFirmOwnerInvitation(CanResetPassword|Model|Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->is_active) {
            return false;
        }

        // firm_users is FORCE-RLS protected and this runs before any
        // firm is known/resolved yet (that is exactly what's being
        // decided) — withUserContext() is the same user-scoped-not-
        // firm-scoped context User::activeFirmUser() itself already
        // uses for this identical "which of MY OWN memberships exist"
        // shape of query, deliberately not runWithFirmContext() (there
        // is no single firm to scope to at this point).
        return (new TenantContextService)->withUserContext(
            $user->id,
            fn () => $user->firmUsers()->where('status', FirmUserStatus::Invited->value)->exists(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return $data;
    }
}
