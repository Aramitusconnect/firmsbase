<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use App\Enums\GovernanceMappingStatus;
use App\Services\MobilePortalCoverageMappingService;
use Tests\TestCase;

class MobilePortalCoverageMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'camera_upload',
        'auto_crop',
        'pdf_conversion',
        'file_quality_warnings',
        'missing_side_detection_ids',
        'checklist_progress',
        'sms_whatsapp_ready_reminder_links',
        'save_and_continue_intake',
        'mobile_payment_plan_visibility',
        'payment_links',
        'client_facing_receipts',
    ];

    private MobilePortalCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MobilePortalCoverageMappingService();
    }

    public function test_all_eleven_mobile_capability_keys_are_declared_explicitly(): void
    {
        $items = $this->service->all();

        $this->assertCount(11, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required mobile capability key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate mobile capability key(s) found.');
    }

    public function test_scanner_and_conversion_capabilities_are_not_found(): void
    {
        $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey('auto_crop')->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey('pdf_conversion')->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey('file_quality_warnings')->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey('missing_side_detection_ids')->status);
    }

    public function test_client_facing_receipts_is_not_found_because_no_client_facing_receipt_concept_exists(): void
    {
        $item = $this->service->byKey('client_facing_receipts');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
        $this->assertStringContainsString('ExpenseReceipt', $item->notes);
    }

    public function test_sms_whatsapp_reminder_links_are_not_falsely_claimed_implemented(): void
    {
        $item = $this->service->byKey('sms_whatsapp_ready_reminder_links');

        $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('never sends anything', $item->notes);
    }

    public function test_camera_upload_and_checklist_progress_are_implemented(): void
    {
        $this->assertSame(GovernanceMappingStatus::Implemented, $this->service->byKey('camera_upload')->status);
        $this->assertSame(GovernanceMappingStatus::Implemented, $this->service->byKey('checklist_progress')->status);
        $this->assertSame(\App\Services\MobilePortalReadinessService::class, $this->service->byKey('camera_upload')->owning_class);
    }

    public function test_save_and_continue_intake_is_implemented(): void
    {
        $this->assertSame(GovernanceMappingStatus::Implemented, $this->service->byKey('save_and_continue_intake')->status);
    }

    public function test_mobile_payment_plan_visibility_is_implemented_schema_ready(): void
    {
        $item = $this->service->byKey('mobile_payment_plan_visibility');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Models\PaymentPlan::class, $item->owning_class);
    }

    public function test_payment_links_are_partially_implemented_because_no_real_provider_exists(): void
    {
        $item = $this->service->byKey('payment_links');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('Stripe', $item->notes);
    }

    public function test_every_mapping_has_evidence_or_notes(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertNotEmpty($item->notes, "Item {$item->item_key} should have explanatory notes.");
        }
    }

    public function test_implemented_partial_not_found_and_not_applicable_buckets_partition_all_items(): void
    {
        $implemented = array_map(fn ($i) => $i->item_key, $this->service->implemented());
        $partial = array_map(fn ($i) => $i->item_key, $this->service->partial());
        $notFound = array_map(fn ($i) => $i->item_key, $this->service->notFound());
        $notApplicable = array_map(fn ($i) => $i->item_key, $this->service->notApplicableYet());

        $union = array_merge($implemented, $partial, $notFound, $notApplicable);

        $this->assertCount(11, array_unique($union));
        $this->assertCount(11, $union, 'Buckets must not overlap.');
    }

    public function test_gaps_never_includes_an_implemented_item(): void
    {
        foreach ($this->service->gaps() as $item) {
            $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }
}
