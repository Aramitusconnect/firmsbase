<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ExpireStaleMarketplaceClaimsCommand;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Services\MarketplaceClaimService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ExpireStaleMarketplaceClaimsCommandTest — Mission 2 (MyAttorney
 * Marketplace Core), sections 20-23. Proves the new
 * `marketplace:claims:expire-stale` command (mirroring
 * SweepTaskOverdueStatusCommandTest's own "first real caller" shape)
 * actually transitions a stale, still-active DirectoryClaim into
 * Expired, leaves everything else untouched, and is registered in
 * bootstrap/app.php's ->withSchedule() at its documented cadence —
 * mirroring OperationsScheduleRegistrationTest's own schedule-
 * registration pattern, the only other schedule-registration test
 * convention found in this codebase.
 */
final class ExpireStaleMarketplaceClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function command(): ExpireStaleMarketplaceClaimsCommand
    {
        return new ExpireStaleMarketplaceClaimsCommand(new MarketplaceClaimService);
    }

    public function test_a_pending_claim_past_its_expiry_is_expired(): void
    {
        $claim = DirectoryClaim::factory()->create([
            'state' => ClaimState::Pending,
            'expires_at' => now()->subDay(),
        ]);

        $exitCode = $this->command()->handle();

        $this->assertSame(0, $exitCode);
        $this->assertSame(ClaimState::Expired, $claim->fresh()->state);
    }

    public function test_a_disputed_claim_past_its_expiry_is_also_expired(): void
    {
        $claim = DirectoryClaim::factory()->disputed()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->command()->handle();

        $this->assertSame(ClaimState::Expired, $claim->fresh()->state);
    }

    public function test_a_claim_not_yet_past_its_expiry_is_left_untouched(): void
    {
        $claim = DirectoryClaim::factory()->create([
            'state' => ClaimState::Pending,
            'expires_at' => now()->addDays(5),
        ]);

        $this->command()->handle();

        $this->assertSame(ClaimState::Pending, $claim->fresh()->state);
    }

    public function test_an_already_decided_claim_past_its_expiry_is_never_touched(): void
    {
        $claim = DirectoryClaim::factory()->approved()->create([
            'expires_at' => now()->subDay(),
        ]);

        $this->command()->handle();

        $this->assertSame(ClaimState::Approved, $claim->fresh()->state);
    }

    public function test_running_with_no_stale_claims_present_does_nothing_harmful(): void
    {
        $claim = DirectoryClaim::factory()->create([
            'state' => ClaimState::Pending,
            'expires_at' => now()->addDays(10),
        ]);

        $exitCode = $this->command()->handle();

        $this->assertSame(0, $exitCode);
        $this->assertSame(ClaimState::Pending, $claim->fresh()->state);
    }

    public function test_the_command_is_scheduled_daily_at_06_35_without_overlapping(): void
    {
        // bootstrap/app.php's ->withSchedule() callback is registered
        // via Illuminate\Console\Application::starting(), which only
        // actually runs once a genuine console Application is
        // bootstrapped — Artisan::call() forces that synchronously.
        Artisan::call('about');

        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'marketplace:claims:expire-stale'));

        $this->assertNotNull($event, 'marketplace:claims:expire-stale must be scheduled in bootstrap/app.php.');
        $this->assertSame('35 6 * * *', $event->expression, 'Must run daily at 06:35.');
        $this->assertTrue($event->withoutOverlapping, 'Must be registered ->withoutOverlapping().');
    }
}
