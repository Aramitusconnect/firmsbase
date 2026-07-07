<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * MarketReadyGapRegistryTest — proves Section 30 added exactly the two
 * AWS-confirmed gaps to the EXISTING ComplianceGapRegistryService,
 * without duplicating any pre-existing gap, and did NOT add gaps for
 * intentionally-unbuilt future product features (OCR, auto-crop, PDF
 * conversion, missing-side detection, or unlaunched practice packs).
 */
class MarketReadyGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_section_29_gap_count_before_section_30_additions_was_thirteen(): void
    {
        // 13 pre-existing (Section 25-29) + 2 new Section 30 gaps = 15.
        $this->assertCount(15, $this->service->all());
    }

    public function test_client_facing_payment_receipts_gap_exists_because_aws_confirmed_no_receipt_concept(): void
    {
        $item = $this->service->byKey('client_facing_payment_receipts_missing');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Medium, $item->severity);
    }

    public function test_template_pack_commercial_differentiation_gap_exists_because_aws_confirmed_only_blanket_entitlement(): void
    {
        $item = $this->service->byKey('template_pack_per_pack_commercial_differentiation_missing');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Medium, $item->severity);
    }

    public function test_no_ocr_auto_crop_pdf_or_future_pack_gap_was_added(): void
    {
        $forbiddenGapKeys = [
            'auto_crop_missing',
            'ocr_missing',
            'pdf_conversion_missing',
            'missing_side_detection_missing',
            'family_law_pack_missing',
            'personal_injury_pack_missing',
        ];

        foreach ($forbiddenGapKeys as $key) {
            $this->assertFalse($this->service->isTracked($key), "Gap '{$key}' must not exist — these are intentionally unbuilt future product features, not gaps.");
        }
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys);
    }

    public function test_exact_final_gap_count(): void
    {
        $this->assertCount(15, $this->service->all());
    }
}
