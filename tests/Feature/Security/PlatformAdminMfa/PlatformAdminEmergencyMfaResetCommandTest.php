<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Models\PlatformAdmin;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlatformAdminEmergencyMfaResetCommandTest —
 * platform-admin:emergency-mfa-reset. Proves: blocked by default
 * outside local/testing without --confirm-production; runs (and writes
 * a real audit event, never a silent path) when the environment is
 * safe or the flag is given.
 *
 * The default `testing` APP_ENV this whole suite already runs under is
 * itself in the command's own local/testing allowlist, so the
 * "blocked by default" tests explicitly swap app()->environment() to
 * something else (production/staging) for the duration of the test.
 */
class PlatformAdminEmergencyMfaResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_without_confirm_production_flag_in_the_testing_environment(): void
    {
        $target = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['hash-one'],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->artisan('platform-admin:emergency-mfa-reset', [
            'email' => $target->email,
            '--reason' => 'lost device and recovery codes',
        ])->assertExitCode(0);

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertNotNull($target->two_factor_reset_at);
    }

    public function test_blocked_in_a_production_like_environment_without_confirm_production(): void
    {
        $this->swapEnvironment('production', function () use (&$target) {
            $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

            $this->artisan('platform-admin:emergency-mfa-reset', [
                'email' => $target->email,
                '--reason' => 'should be blocked',
            ])->assertExitCode(1);
        });

        $target->refresh();
        $this->assertSame('JBSWY3DPEHPK3PXP', $target->two_factor_secret, 'A blocked run must make no change at all.');

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'mfa_reset_by_emergency_command')->count()
        );
        $this->assertSame(0, $count, 'A blocked run must write no audit event either — never a partial, half-silent path.');
    }

    public function test_runs_in_a_production_like_environment_with_confirm_production(): void
    {
        $target = null;

        $this->swapEnvironment('production', function () use (&$target) {
            $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

            $this->artisan('platform-admin:emergency-mfa-reset', [
                'email' => $target->email,
                '--reason' => 'sole superadmin, approved break-glass',
                '--confirm-production' => true,
            ])->assertExitCode(0);
        });

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNotNull($target->two_factor_reset_at);
    }

    public function test_writes_a_console_attributed_audit_event_when_it_runs(): void
    {
        $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->artisan('platform-admin:emergency-mfa-reset', [
            'email' => $target->email,
            '--reason' => 'lost device and recovery codes',
        ])->assertExitCode(0);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'mfa_reset_by_emergency_command')
                ->first()
        );

        $this->assertNotNull($row);
        $this->assertSame('console', $row->actor_type);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->firm_id);
        $this->assertSame('platform_admin_mfa', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($target->id, $metadata['target_platform_admin_id']);
        $this->assertSame('lost device and recovery codes', $metadata['reason']);
    }

    public function test_fails_clearly_for_an_unknown_email(): void
    {
        $this->artisan('platform-admin:emergency-mfa-reset', [
            'email' => 'nobody@example.test',
            '--reason' => 'test',
        ])->assertExitCode(1);
    }

    public function test_fails_when_no_reason_is_given_non_interactively(): void
    {
        $target = PlatformAdmin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->artisan('platform-admin:emergency-mfa-reset', [
            'email' => $target->email,
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $target->refresh();
        $this->assertSame('JBSWY3DPEHPK3PXP', $target->two_factor_secret);
    }

    /**
     * Swaps app()->environment() to $environment for the duration of
     * $callback, restoring the real environment afterward even if the
     * callback throws. Application::environment() reads the `env`
     * container binding (`$this['env']`), not a plain property — this
     * rebinds that container entry directly rather than reflecting into
     * a property that does not exist on this Laravel version.
     */
    private function swapEnvironment(string $environment, callable $callback): void
    {
        $original = App::make('env');

        App::instance('env', $environment);

        try {
            $callback();
        } finally {
            App::instance('env', $original);
        }
    }
}
