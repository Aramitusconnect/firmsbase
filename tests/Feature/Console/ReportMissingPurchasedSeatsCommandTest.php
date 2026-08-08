<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmUser;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ReportMissingPurchasedSeatsCommandTest — Firm Feature Manifest §12
 * backfill command for pre-existing commercial firms. Proves: dry-run
 * reports correctly without mutating anything; apply mode sets exactly
 * the supplied value for exactly the supplied firm; refuses to silently
 * overwrite a different existing value without --force; idempotent
 * re-run with the same value is a no-op success; never invents a
 * number in either mode.
 */
final class ReportMissingPurchasedSeatsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function licensedFirm(?int $purchasedSeats, ?Plan $plan = null): Firm
    {
        $firm = Firm::factory()->create();
        $plan ??= Plan::factory()->create();

        $this->createWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create([
            'plan_id' => $plan->id,
            'purchased_seats' => $purchasedSeats,
        ]));

        return $firm;
    }

    // ------------------------------------------------------------
    // Dry-run / report mode
    // ------------------------------------------------------------

    public function test_dry_run_reports_a_firm_with_a_plan_and_no_purchased_seats(): void
    {
        $plan = Plan::factory()->create(['name' => 'Reportable Plan']);
        $firm = $this->licensedFirm(null, $plan);
        $this->createWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create());

        // Table content is rendered directly to the output stream by
        // Symfony's Table component, bypassing the OutputStyle mock
        // Laravel's expectsOutputToContain() inspects — Artisan::call()
        // + Artisan::output() reads the real rendered buffer instead.
        $exitCode = Artisan::call('firms:report-missing-purchased-seats');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($firm->name, $output);
        $this->assertStringContainsString('Reportable Plan', $output);

        // Never mutated.
        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertNull($fresh->purchased_seats, 'Dry-run must never mutate any row.');
    }

    public function test_dry_run_does_not_report_a_firm_that_already_has_purchased_seats_set(): void
    {
        $firm = $this->licensedFirm(10);

        $this->artisan('firms:report-missing-purchased-seats')
            ->assertExitCode(0)
            ->expectsOutputToContain('No commercial firm is missing a purchased-seat quantity.');
    }

    public function test_dry_run_does_not_report_a_plan_less_firm(): void
    {
        $firm = Firm::factory()->create();
        // No FirmLicense at all — a plan-less firm has no seat concept,
        // must never appear in this report.

        $this->artisan('firms:report-missing-purchased-seats')
            ->assertExitCode(0)
            ->expectsOutputToContain('No commercial firm is missing a purchased-seat quantity.');
    }

    // ------------------------------------------------------------
    // Apply mode
    // ------------------------------------------------------------

    public function test_apply_sets_exactly_the_supplied_value_for_exactly_the_supplied_firm(): void
    {
        $firmA = $this->licensedFirm(null);
        $firmB = $this->licensedFirm(null);

        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firmA->id,
            '--seats' => 15,
        ])->assertExitCode(0);

        $freshA = $this->runWithFirmContext($firmA, fn () => FirmLicense::query()->where('firm_id', $firmA->id)->first());
        $freshB = $this->runWithFirmContext($firmB, fn () => FirmLicense::query()->where('firm_id', $firmB->id)->first());

        $this->assertSame(15, $freshA->purchased_seats);
        $this->assertNull($freshB->purchased_seats, "A different firm's license must be completely unaffected.");
    }

    public function test_apply_requires_both_firm_and_seats(): void
    {
        $firm = $this->licensedFirm(null);

        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firm->id,
        ])->assertExitCode(1);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertNull($fresh->purchased_seats);
    }

    public function test_apply_rejects_a_non_positive_seats_value(): void
    {
        $firm = $this->licensedFirm(null);

        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firm->id,
            '--seats' => 0,
        ])->assertExitCode(1);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertNull($fresh->purchased_seats);
    }

    public function test_apply_fails_for_a_firm_with_no_license_at_all(): void
    {
        $firm = Firm::factory()->create();

        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firm->id,
            '--seats' => 5,
        ])->assertExitCode(1);
    }

    public function test_apply_refuses_to_silently_overwrite_a_different_existing_value(): void
    {
        $firm = $this->licensedFirm(10);

        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firm->id,
            '--seats' => 20,
        ])->assertExitCode(1);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertSame(10, $fresh->purchased_seats, 'A differing existing value must never be silently overwritten.');
    }

    public function test_apply_with_force_overwrites_a_different_existing_value(): void
    {
        $firm = $this->licensedFirm(10);

        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firm->id,
            '--seats' => 20,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertSame(20, $fresh->purchased_seats);
    }

    public function test_apply_is_idempotent_when_re_run_with_the_same_value(): void
    {
        $firm = $this->licensedFirm(10);

        // No --force needed — the identical value is a no-op success.
        $this->artisan('firms:report-missing-purchased-seats', [
            '--apply' => true,
            '--firm' => $firm->id,
            '--seats' => 10,
        ])->assertExitCode(0);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertSame(10, $fresh->purchased_seats);
    }

    // ------------------------------------------------------------
    // "used" count in the report reflects Active+Invited+Suspended
    // ------------------------------------------------------------

    public function test_dry_run_reports_the_correct_active_invited_suspended_user_count(): void
    {
        $plan = Plan::factory()->create();
        $firm = $this->licensedFirm(null, $plan);

        $this->createWithFirmContext($firm, function () use ($firm): void {
            FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(['status' => FirmUserStatus::Active]);
            FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create(['status' => FirmUserStatus::Invited]);
            FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create(['status' => FirmUserStatus::Suspended]);
            FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Receptionist)->create(['status' => FirmUserStatus::Removed]);
        });

        $exitCode = Artisan::call('firms:report-missing-purchased-seats');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($firm->name, $output);

        // 3 (active+invited+suspended), the Removed row excluded — the
        // report's own count column, matching FirmSeatCapacityService's
        // usedSeats() definition (proven directly in
        // FirmSeatCapacityServiceTest).
        $this->assertMatchesRegularExpression('/\|\s*3\s*\|\s*$/m', $output, 'Expected the used-count column to read 3 (Active+Invited+Suspended, Removed excluded).');

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());
        $this->assertNull($fresh->purchased_seats);
    }
}
