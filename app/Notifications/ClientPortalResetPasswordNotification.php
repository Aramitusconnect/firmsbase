<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/**
 * ClientPortalResetPasswordNotification — Checkpoint 4 ("Plaid financial
 * evidence add-on"), Client Portal "secure password setup/reset"
 * requirement. Laravel's own default `ResetPassword` notification builds
 * its reset URL via `route('password.reset', ...)` — a plain named route
 * that does not exist anywhere in this app (every panel is Filament-scoped;
 * see bootstrap/app.php's own guard-aware `redirectGuestsTo()` fix for the
 * identical class of gap on the login side). Found and fixed during this
 * checkpoint's own test-writing pass: `Password::sendResetLink()` for a
 * Client Portal user threw `RouteNotFoundException: Route [password.reset]
 * not defined.` without this override.
 *
 * Only overrides `resetUrl()` — everything else (mail subject/body,
 * `via()`) is inherited unchanged from Laravel's own notification.
 */
class ClientPortalResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable)
    {
        return url(route('filament.client-portal.auth.password-reset.reset', [
            'email' => $notifiable->getEmailForPasswordReset(),
            'token' => $this->token,
        ], false));
    }
}
