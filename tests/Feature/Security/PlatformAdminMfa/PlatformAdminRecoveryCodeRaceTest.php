<?php

declare(strict_types=1);

namespace Tests\Feature\Security\PlatformAdminMfa;

use App\Filament\MultiFactor\AuditedAppAuthentication;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PlatformAdminRecoveryCodeRaceTest — MFA design proposal §9's
 * "recovery-code consumption race test". Proves two NEAR-SIMULTANEOUS
 * submissions of the exact same recovery code produce exactly one
 * success — the mechanism this exercises is
 * Filament\Auth\MultiFactor\App\AppAuthentication::verifyRecoveryCode()
 * (inherited unchanged by AuditedAppAuthentication — see that class's
 * own docblock for why this method is NOT overridden): a
 * Cache::lock()-wrapped DB::transaction() with `lockForUpdate()` on the
 * admin's own row.
 *
 * Genuine OS-level concurrency (pcntl_fork()), not sequential calls
 * pretending to race: two real, separate PHP processes each open their
 * OWN fresh database connection and call verifyRecoveryCode()
 * against the SAME still-unconsumed code at nearly the same instant.
 * A naive, unlocked "check the array, then remove the match, then
 * save" implementation would let both succeed under genuine
 * concurrency; this proves the real one does not.
 *
 * This environment's CACHE_STORE is `array` (see phpunit.xml) — not
 * shared across OS processes, so the outer Cache::lock() in
 * verifyRecoveryCode() provides no real cross-process exclusion here.
 * The correctness guarantee this test actually proves comes from the
 * INNER `DB::transaction()`'s `lockForUpdate()` row lock, which IS a
 * genuine, cross-process, cross-connection PostgreSQL guarantee
 * regardless of cache driver — documented here so a future reader does
 * not mistake this for testing the cache layer.
 *
 * No RefreshDatabase: a forked child process must open a FRESH
 * database connection that can actually see the fixture row, which
 * requires it to be really committed — RefreshDatabase's per-test
 * transaction wrapping would make the fixture invisible to the child's
 * connection (Postgres MVCC visibility). Fixture rows are created and
 * explicitly cleaned up by this test itself instead.
 */
class PlatformAdminRecoveryCodeRaceTest extends TestCase
{
    private ?int $createdAdminId = null;

    protected function tearDown(): void
    {
        if ($this->createdAdminId !== null) {
            PlatformAdmin::query()->where('id', $this->createdAdminId)->delete();
        }

        parent::tearDown();
    }

    public function test_two_near_simultaneous_submissions_of_the_same_recovery_code_produce_exactly_one_success(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment — cannot exercise genuine process-level concurrency for this race test.');
        }

        $recoveryCode = 'race-'.Str::random(16);

        $admin = PlatformAdmin::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [Hash::make($recoveryCode)],
            'two_factor_confirmed_at' => now(),
        ]);
        $this->createdAdminId = $admin->id;

        // Sanity check: the fixture must really be committed and
        // independently visible before forking, or this whole test is
        // meaningless.
        $this->assertNotNull(PlatformAdmin::query()->find($this->createdAdminId));

        $childResultFile = tempnam(sys_get_temp_dir(), 'mfa_race_child_');
        $parentResultFile = tempnam(sys_get_temp_dir(), 'mfa_race_parent_');

        DB::disconnect();
        DB::purge();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed — cannot run this race test.');
        }

        if ($pid === 0) {
            // Child process. Every branch below writes a result file
            // before exiting — never lets an exception propagate
            // uncaught, which (inheriting the parent's PHPUnit/Laravel
            // process state via fork) could otherwise trigger unwanted
            // inherited shutdown behavior instead of a clean, isolated
            // process exit.
            try {
                DB::purge();
                $appAuthentication = app(AuditedAppAuthentication::class);
                $freshAdmin = PlatformAdmin::query()->find($this->createdAdminId);
                $ok = $freshAdmin !== null && $appAuthentication->verifyRecoveryCode($recoveryCode, $freshAdmin);
                file_put_contents($childResultFile, $ok ? '1' : '0');
            } catch (\Throwable) {
                // A losing attempt may legitimately throw (e.g. Filament's
                // own getRecoveryCodes() throws once the array becomes
                // empty after the winner consumes the only code) rather
                // than return false — either way this branch did not
                // succeed.
                file_put_contents($childResultFile, '0');
            }

            exit(0);
        }

        // Parent process: as close to the fork point as possible to
        // maximize overlap with the child.
        try {
            DB::purge();
            $appAuthentication = app(AuditedAppAuthentication::class);
            $freshAdmin = PlatformAdmin::query()->find($this->createdAdminId);
            $parentOk = $freshAdmin !== null && $appAuthentication->verifyRecoveryCode($recoveryCode, $freshAdmin);
            file_put_contents($parentResultFile, $parentOk ? '1' : '0');
        } catch (\Throwable) {
            file_put_contents($parentResultFile, '0');
        }

        pcntl_waitpid($pid, $status);

        $childResult = (int) trim((string) file_get_contents($childResultFile));
        $parentResult = (int) trim((string) file_get_contents($parentResultFile));

        @unlink($childResultFile);
        @unlink($parentResultFile);

        DB::purge();

        $this->assertSame(
            1,
            $childResult + $parentResult,
            'Exactly one of the two near-simultaneous recovery-code submissions must succeed.'
        );

        $finalAdmin = PlatformAdmin::query()->find($this->createdAdminId);
        $this->assertNotNull($finalAdmin, 'The admin row must still exist after both concurrent attempts.');
        $this->assertSame([], $finalAdmin->two_factor_recovery_codes, 'The single consumed code must be removed, leaving no codes behind (only one existed).');
    }
}
