<?php

namespace Tests\Feature\Phase7Reuse;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\PlatformAdmin;
use App\Services\HighRiskPlatformChangePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correction #7: request()'s new `array $metadata = []` parameter must
 * be fully backward-compatible — every pre-existing Phase 7 caller/test
 * that omits it must behave exactly as before (metadata defaults to an
 * empty array, not null, on the already-array-cast column). No other
 * line of HighRiskPlatformChangePolicyService changed.
 */
class HighRiskPlatformChangePolicyServiceBackwardCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_without_metadata_argument_defaults_to_an_empty_array(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $request = app(HighRiskPlatformChangePolicyService::class)->request(
            HighRiskChangeType::ProductionDataDeletion,
            $admin,
            'Routine production data cleanup.',
        );

        $this->assertSame([], $request->metadata);
        $this->assertSame(HighRiskChangeRequestStatus::Pending, $request->status);
    }

    public function test_request_with_metadata_persists_it_on_the_existing_column(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $request = app(HighRiskPlatformChangePolicyService::class)->request(
            HighRiskChangeType::TrustModeActivation,
            $admin,
            'Trust mode activation for a pilot firm.',
            ['firm_id' => 42],
        );

        $this->assertSame(42, $request->fresh()->metadata['firm_id']);
    }

    public function test_the_full_two_person_approval_flow_still_works_unchanged(): void
    {
        $firstAdmin = PlatformAdmin::factory()->create();
        $secondAdmin = PlatformAdmin::factory()->create();
        $service = app(HighRiskPlatformChangePolicyService::class);

        $request = $service->request(HighRiskChangeType::ProductionDataDeletion, $firstAdmin, 'Cleanup.');
        $service->firstApprove($request, $firstAdmin);

        $decision = $service->secondApprove($request->fresh(), $secondAdmin);

        $this->assertSame(HighRiskChangeRequestStatus::Approved, $decision->status);
    }
}
