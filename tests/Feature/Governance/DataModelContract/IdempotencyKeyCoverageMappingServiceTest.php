<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Enums\GovernanceMappingStatus;
use App\Services\IdempotencyKeyCoverageMappingService;
use Tests\TestCase;

class IdempotencyKeyCoverageMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'payment_collection',
        'payment_plan_installment_collection',
        'webhook_event_recording',
        'webhook_delivery_attempts',
        'import_apply',
        'retry_sensitive_jobs',
    ];

    private IdempotencyKeyCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IdempotencyKeyCoverageMappingService();
    }

    public function test_declares_all_six_retry_sensitive_operation_keys(): void
    {
        $items = $this->service->all();

        $this->assertCount(6, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required operation key: {$key}");
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }

    public function test_payment_collection_maps_to_payments_idempotency_key(): void
    {
        $item = $this->service->byKey('payment_collection');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Models\Payment::class, $item->owning_class);
        $this->assertStringContainsString('idempotency_key', $item->notes);
    }

    public function test_webhook_delivery_attempts_is_partially_implemented(): void
    {
        $item = $this->service->byKey('webhook_delivery_attempts');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('no', $item->notes);
    }

    public function test_import_apply_maps_to_import_duplicate_detection_service(): void
    {
        $item = $this->service->byKey('import_apply');

        $this->assertSame(\App\Services\ImportDuplicateDetectionService::class, $item->owning_class);
    }

    public function test_gaps_includes_only_not_found_or_partially_implemented_items(): void
    {
        $gaps = $this->service->gaps();

        $this->assertNotEmpty($gaps);

        foreach ($gaps as $item) {
            $this->assertContains($item->status, [
                GovernanceMappingStatus::NotFound,
                GovernanceMappingStatus::PartiallyImplemented,
            ]);
        }

        // payment_collection is fully Implemented, so it must never appear in gaps().
        $gapKeys = array_map(fn ($item) => $item->item_key, $gaps);
        $this->assertNotContains('payment_collection', $gapKeys);
    }
}
