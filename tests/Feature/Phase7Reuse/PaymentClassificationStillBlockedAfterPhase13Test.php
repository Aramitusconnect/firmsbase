<?php

namespace Tests\Feature\Phase7Reuse;

use App\Enums\PaymentClassification;
use App\Enums\PaymentMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Services\PaymentClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REQUIRED test (approved manifest / correction #2): shipping Phase 13
 * does NOT itself "accept" the trust accounting foundation — project
 * rule 6 ("Trust/IOLTA deposits must remain blocked until the full
 * trust accounting foundation is complete and accepted") is read
 * literally. PaymentClassificationService::classify() must still
 * hard-block any requested PaymentClassification::TrustIoltaPayment,
 * even for a firm whose payment_mode is operating_and_trust, even after
 * every other Phase 13 file in this package exists. TrustDepositService
 * is a wholly separate, parallel path into trust_ledger_entries that
 * never touches this one.
 */
class PaymentClassificationStillBlockedAfterPhase13Test extends TestCase
{
    use RefreshDatabase;

    public function test_trust_iolta_payment_is_still_blocked_regardless_of_payment_mode(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['payment_mode' => PaymentMode::OperatingAndTrust]);

        $result = app(PaymentClassificationService::class)->classify($firm, PaymentClassification::TrustIoltaPayment);

        $this->assertFalse($result->accepted);
        $this->assertSame(PaymentClassification::BlockedPayment, $result->resolvedClassification);
        $this->assertStringContainsString('Phase 13 trust accounting foundation is accepted', $result->rejectionReason);
    }

    public function test_operating_payment_is_still_accepted_normally(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create(['payment_mode' => PaymentMode::OperatingAndTrust]);

        $result = app(PaymentClassificationService::class)->classify($firm, PaymentClassification::OperatingPayment);

        $this->assertTrue($result->accepted);
        $this->assertSame(PaymentClassification::OperatingPayment, $result->resolvedClassification);
    }
}
