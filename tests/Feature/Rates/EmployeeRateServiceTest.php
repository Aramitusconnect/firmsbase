<?php

namespace Tests\Feature\Rates;

use App\Models\Firm;
use App\Models\User;
use App\Services\EmployeeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmployeeRateService();
    }

    public function test_set_rate_creates_an_open_ended_row(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $rate = $this->service->setRate($firm, $user, 25000, 12000);

        $this->assertNull($rate->effective_to);
        $this->assertSame(25000, $rate->billing_rate_cents);
        $this->assertSame(12000, $rate->cost_rate_cents);
    }

    public function test_setting_a_new_rate_closes_out_the_previous_open_rate(): void
    {
        // Section 39A-3K follow-up: employee_rates is now FORCE RLS
        // enabled. EmployeeRateService::setRate() self-wraps in its own
        // runWithFirmContext() (see the service's own docblock), which
        // ALWAYS clears the PostgreSQL session/PHP-memory tenant
        // context again before returning (even on success) — so the
        // re-reads below (->fresh() and the raw count query), which
        // happen AFTER setRate() has already returned, would otherwise
        // run with no context active and be fail-closed to zero rows
        // under FORCE RLS. Re-querying under an explicit, scoped
        // runWithFirmContext() (rather than setting context for the
        // whole test class) is the narrow fix — setRate() itself still
        // establishes its own context internally exactly as before.
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $first = $this->service->setRate($firm, $user, 20000, 10000, effectiveFrom: now()->subMonths(2));
        $second = $this->service->setRate($firm, $user, 25000, 12000, effectiveFrom: now());

        [$firstFresh, $secondFresh, $openCount] = $this->runWithFirmContext($firm, fn () => [
            $first->fresh(),
            $second->fresh(),
            \App\Models\EmployeeRate::withoutGlobalScopes()
                ->where('firm_id', $firm->id)
                ->where('user_id', $user->id)
                ->whereNull('effective_to')
                ->count(),
        ]);

        $this->assertNotNull($firstFresh->effective_to);
        $this->assertNull($secondFresh->effective_to);
        $this->assertSame(1, $openCount);
    }

    public function test_current_rate_for_returns_the_rate_active_on_a_historical_date(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $old = $this->service->setRate($firm, $user, 20000, 10000, effectiveFrom: now()->subMonths(2));
        $new = $this->service->setRate($firm, $user, 25000, 12000, effectiveFrom: now()->subDays(1));

        $asOfOld = $this->service->currentRateFor($firm, $user, now()->subMonths(1));
        $asOfNow = $this->service->currentRateFor($firm, $user);

        $this->assertSame($old->id, $asOfOld->id);
        $this->assertSame($new->id, $asOfNow->id);
    }

    public function test_current_rate_for_returns_null_when_no_rate_has_ever_been_set(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $this->assertNull($this->service->currentRateFor($firm, $user));
    }

    public function test_rates_are_firm_scoped(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = User::factory()->create();

        $this->service->setRate($firmA, $user, 20000, 10000);

        $this->assertNull($this->service->currentRateFor($firmB, $user));
    }
}
