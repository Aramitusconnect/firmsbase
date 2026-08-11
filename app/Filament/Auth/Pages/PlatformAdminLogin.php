<?php

declare(strict_types=1);

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Concerns\ThrottlesLoginsPerAccount;
use Filament\Auth\Pages\Login;
use Filament\Schemas\Schema;

/**
 * PlatformAdminLogin — FirmsVault Admin Control Center MFA design
 * proposal §3. Wired in AdminPanelProvider via ->login(PlatformAdminLogin::class).
 *
 * Resolved uncertainty (design proposal's uncertainty #1, now a
 * finalized decision — not re-litigated here): "remember me" is
 * disabled ENTIRELY for the platform-admin panel. Real usability cost
 * accepted deliberately (every SuperAdmin re-proves both factors every
 * session, forever) in exchange for closing a real bypass: Illuminate\
 * Auth\SessionGuard::userFromRecaller() authenticates purely from the
 * recaller cookie and calls fireLoginEvent() directly — it never
 * touches Login::authenticate(), which is the only place the TOTP/
 * recovery-code challenge logic runs. A remember-me cookie for this
 * guard would therefore let a stolen/replayed cookie skip the MFA
 * challenge outright, panel-wide, for as long as the cookie remains
 * valid.
 *
 * The fix is simply omitting the remember checkbox from the schema:
 * Login::authenticate() reads `$data['remember'] ?? false`, so with no
 * `remember` key ever present in form state, every login for this
 * panel behaves as if the box were always left unchecked — no
 * recaller cookie is ever issued (Illuminate\Auth\SessionGuard::
 * attemptWhen() only queues the recaller cookie when $remember is
 * truthy). EnsurePlatformAdminMfaIsEnrolledAndVerified's step 4
 * (session-verification) is the belt-and-suspenders backstop against
 * a cookie that predates this policy or is forged/replayed anyway —
 * this page is the structural fix, that middleware step is defense in
 * depth, not a substitute for it.
 *
 * Also uses ThrottlesLoginsPerAccount (Mission 1B, section 13) — the
 * highest-security surface in this application gets the same
 * account-level brute-force layer as every other panel, on top of the
 * IP-based bucket this class already isolates for free by virtue of
 * being its own distinct Login subclass.
 */
class PlatformAdminLogin extends Login
{
    use ThrottlesLoginsPerAccount;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }
}
