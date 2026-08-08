<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Exceptions\InvalidPurchasedSeatsException;
use App\Models\Firm;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\FirmProvisioningService;
use App\ValueObjects\FirmProvisioningInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FirmProvisioningSeatsTest — Firm Feature Manifest §12's flat
 * per-firm seat model, provisioning-layer proof. Complements the
 * broader FirmProvisioningServiceTest (which now also asserts the
 * exact purchased_seats value on its own plan-provisioning tests) with
 * focused coverage of the three specific rules this addition
 * introduced: a plan-provisioned firm gets the EXACT supplied
 * purchasedSeats value, a plan-less firm gets no FirmLicense/no seat
 * concept at all (unchanged prior behavior), and missing/invalid seat
 * input is rejected with a clear validation error whenever a plan is
 * selected.
 */
final class FirmProvisioningSeatsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(['is_active' => true]);
    }

    private function input(array $overrides = []): FirmProvisioningInput
    {
        $defaults = [
            'idempotencyKey' => (string) Str::uuid(),
            'firmName' => 'Seats Test Firm',
            'legalName' => null,
            'organizationMode' => FirmOrganizationProvisioningMode::None,
            'organizationId' => null,
            'newOrganizationName' => null,
            'ownerName' => 'Ada Owner',
            'ownerEmail' => 'seats-'.Str::random(8).'@example.test',
            'reuseExistingUser' => false,
            'customerType' => CustomerType::LawFirm,
            'deploymentMode' => DeploymentMode::Saas,
            'planId' => null,
            'trialDaysOverride' => null,
            'note' => null,
            'purchasedSeats' => null,
        ];

        return new FirmProvisioningInput(...array_merge($defaults, $overrides));
    }

    private function service(): FirmProvisioningService
    {
        return app(FirmProvisioningService::class);
    }

    public function test_a_plan_provisioned_firm_gets_the_exact_supplied_purchased_seats_value(): void
    {
        $plan = Plan::factory()->create();

        $result = $this->service()->provision($this->input(['planId' => $plan->id, 'purchasedSeats' => 22]), $this->actor());

        $license = $this->runWithFirmContext($result->firm, fn () => $result->firm->licenses()->first());

        $this->assertNotNull($license);
        $this->assertSame(22, $license->purchased_seats);
    }

    public function test_a_plan_less_firm_gets_no_firm_license_at_all(): void
    {
        $result = $this->service()->provision($this->input(), $this->actor());

        $licenseCount = $this->runWithFirmContext($result->firm, fn () => $result->firm->licenses()->count());

        $this->assertSame(0, $licenseCount, 'A plan-less firm must have zero FirmLicense rows — no seat concept at all.');
    }

    public function test_a_plan_less_firm_does_not_require_purchased_seats(): void
    {
        // No exception — a plan-less provision with no purchasedSeats
        // at all must succeed exactly as before this addition.
        $result = $this->service()->provision($this->input(['purchasedSeats' => null]), $this->actor());

        $this->assertNotNull($result->firm->id);
    }

    public function test_missing_purchased_seats_is_rejected_when_a_plan_is_selected(): void
    {
        $plan = Plan::factory()->create();
        $firmCountBefore = Firm::query()->count();

        $this->expectException(InvalidPurchasedSeatsException::class);

        try {
            $this->service()->provision($this->input(['planId' => $plan->id, 'purchasedSeats' => null]), $this->actor());
        } finally {
            $this->assertSame($firmCountBefore, Firm::query()->count(), 'No orphan Firm may remain after a validation rejection.');
        }
    }

    public function test_zero_purchased_seats_is_rejected_when_a_plan_is_selected(): void
    {
        $plan = Plan::factory()->create();

        $this->expectException(InvalidPurchasedSeatsException::class);

        $this->service()->provision($this->input(['planId' => $plan->id, 'purchasedSeats' => 0]), $this->actor());
    }

    public function test_negative_purchased_seats_is_rejected_when_a_plan_is_selected(): void
    {
        $plan = Plan::factory()->create();

        $this->expectException(InvalidPurchasedSeatsException::class);

        $this->service()->provision($this->input(['planId' => $plan->id, 'purchasedSeats' => -5]), $this->actor());
    }
}
