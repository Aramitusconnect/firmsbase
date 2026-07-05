<?php

namespace Tests\Feature\Trust\Concerns;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Enums\PaymentMode;
use App\Services\EntitlementService;
use App\Services\TrustModeActivationService;

/**
 * Shared setup for every Phase 13 test that needs a firm which passes
 * ALL FIVE TrustEligibilityService conditions (correction #9). Exercises
 * the real Phase 7 two-person approval flow (two DIFFERENT PlatformAdmins)
 * rather than faking an Approved status directly, so these tests also
 * incidentally prove the Phase 7 reuse path stays wired correctly.
 */
trait SetsUpTrustEligibleFirm
{
    protected function makeTrustEligibleFirm(): Firm
    {
        $firm = Firm::factory()->create();

        FirmSettings::factory()->forFirm($firm)->create([
            'payment_mode' => PaymentMode::OperatingAndTrust,
            'trust_iolta_protection' => true,
        ]);

        app(EntitlementService::class)->setForSource(
            $firm,
            'trust_iolta',
            EntitlementSource::AdminOverride,
            true,
        );

        $firstAdmin = PlatformAdmin::factory()->create();
        $secondAdmin = PlatformAdmin::factory()->create();

        $activationService = app(TrustModeActivationService::class);

        $request = $activationService->requestActivation($firm, $firstAdmin, 'Pilot immigration-law trust accounting activation.');
        $activationService->firstApprove($request, $firstAdmin);
        $activationService->secondApprove($request, $secondAdmin);

        $recordedBy = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $activationService->linkApprovedActivation($firm, $request->fresh(), $recordedBy);

        return $firm->fresh();
    }
}
