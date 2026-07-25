<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\MultiFactor\AuditedAppAuthentication;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `platform-admin:emergency-mfa-reset` — FirmsVault Admin Control
 * Center MFA design proposal, resolved uncertainty #2 (now a finalized
 * decision — not re-litigated here): the sole-SuperAdmin emergency
 * recovery path IS built, deliberately narrow. See
 * docs/integrations/runbooks/platform-admin-emergency-mfa-reset.md for
 * the operational runbook (when to use this, required approvals,
 * evidence to capture).
 *
 * Exists for exactly one scenario: a SuperAdmin has lost both their
 * authenticator device AND their recovery codes, and — being the sole
 * active SuperAdmin — has no other SuperAdmin able to run
 * App\Filament\Actions\Platform\ResetPlatformAdminMfaAction on their
 * behalf from the panel. This command requires direct server/console
 * access (SSH + deploy credentials, or equivalent) — a materially
 * different, already-privileged trust boundary from the panel's own
 * password+MFA login, which is exactly why it is safe to make this a
 * password-only (no MFA challenge of its own) console tool rather than
 * either "no software recovery path at all" (an operational landmine —
 * see the design proposal's own framing) or a panel-reachable backdoor
 * (which would undermine the panel's own MFA guarantee).
 *
 * Blocked by default outside local/testing environments. Running it
 * against a production-like environment (anything other than
 * app()->environment(['local', 'testing']) — mirroring
 * SeedDataSecurityAuditService's own environment-allowlist convention)
 * requires the explicit --confirm-production flag. This is a
 * SAFETY confirmation, not an authorization mechanism — actual
 * authorization is server/console access itself, which this command
 * cannot verify or strengthen from within PHP.
 *
 * Never a silent path: every actual reset (never a blocked attempt —
 * see PlatformAdminAuditEventRecorder::recordConsoleEvent()'s own
 * docblock for why a blocked/refused run intentionally makes no
 * database write at all, including no audit row, so the safety check
 * itself never requires database access) writes exactly one
 * `security_events` row via PlatformAdminAuditEventRecorder::
 * recordConsoleEvent() — the same recorder class the in-panel
 * ResetPlatformAdminMfaAction uses, attributed as a distinct
 * `actor_type = 'console'` (no PlatformAdmin actor exists to attribute
 * to in the scenario this command exists for — see that method's own
 * docblock).
 *
 * Clears MFA state through the exact same AuditedAppAuthentication
 * calls (saveSecret()/saveRecoveryCodes()) that
 * PlatformAdminMfaResetService::reset() and Filament's own
 * DisableAppAuthenticationAction use — so the target's own
 * mfa_disabled/mfa_recovery_codes_cleared audit rows still fire exactly
 * as they would from any other disable path, in addition to this
 * command's own mfa_reset_by_emergency_command row.
 */
class PlatformAdminEmergencyMfaResetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'platform-admin:emergency-mfa-reset
        {email : The platform administrator whose MFA enrollment should be cleared}
        {--reason= : Why this is being run (recorded in the audit trail) — prompted for if omitted}
        {--confirm-production : Required to run this command outside a local/testing environment}';

    protected $description = 'Emergency, console-only reset of a platform administrator\'s MFA enrollment (sole-SuperAdmin lost-device/lost-recovery-codes recovery path).';

    public function handle(AuditedAppAuthentication $appAuthentication, PlatformAdminAuditEventRecorder $auditRecorder): int
    {
        if ((! app()->environment(['local', 'testing'])) && (! $this->option('confirm-production'))) {
            $this->components->error(sprintf(
                'Refusing to run in the "%s" environment without --confirm-production. This command bypasses the panel\'s own MFA challenge entirely — re-run with --confirm-production only if you have confirmed this is an authorized emergency recovery.',
                app()->environment(),
            ));

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');

        $target = PlatformAdmin::query()->where('email', $email)->first();

        if ($target === null) {
            $this->components->error("No platform administrator found with email [{$email}].");

            return self::FAILURE;
        }

        $reason = $this->option('reason');

        if (blank($reason)) {
            $reason = $this->input->isInteractive()
                ? $this->ask('Reason for this emergency reset (recorded in the audit trail)')
                : null;
        }

        if (blank($reason)) {
            $this->components->error('A --reason is required (this action is always audited).');

            return self::FAILURE;
        }

        DB::transaction(function () use ($appAuthentication, $auditRecorder, $target, $reason): void {
            $appAuthentication->saveSecret($target, null);
            $appAuthentication->saveRecoveryCodes($target, null);

            $target->forceFill(['two_factor_reset_at' => now()])->save();

            $auditRecorder->recordConsoleEvent(
                'mfa_reset_by_emergency_command',
                'platform_admin_mfa',
                [
                    'target_platform_admin_id' => $target->id,
                    'target_platform_admin_uuid' => $target->uuid,
                    'reason' => (string) $reason,
                    'environment' => app()->environment(),
                    'os_user' => get_current_user(),
                    'hostname' => gethostname() ?: null,
                ],
            );
        });

        $this->components->info("MFA cleared for [{$target->email}]. They will be required to re-enroll on their next login, and any current session will be forced to log out immediately.");

        return self::SUCCESS;
    }
}
