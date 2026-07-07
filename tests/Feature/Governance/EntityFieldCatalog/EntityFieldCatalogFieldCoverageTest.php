<?php

namespace Tests\Feature\Governance\EntityFieldCatalog;

use App\Enums\GovernanceMappingStatus;
use App\Services\EntityFieldCatalogMappingService;
use Tests\TestCase;

class EntityFieldCatalogFieldCoverageTest extends TestCase
{
    private EntityFieldCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityFieldCatalogMappingService();
    }

    /**
     * Representative fields that are normalized/renamed must never be
     * falsely reported NotFound.
     */
    public function test_normalized_or_renamed_fields_are_not_falsely_not_found(): void
    {
        $keys = [
            'firm_leads.practice_area_interest',
            'consultations.outcome',
            'matters.readiness_score',
            'license_files.signature_alg',
            'license_files.grace_days',
            'deadlines.reminder_policy_id',
            'payment_plans.dunning_policy_id',
            'platform_invoices.usage_attribution_json',
        ];

        foreach ($keys as $key) {
            $item = $this->service->byKey($key);

            $this->assertNotNull($item, "Missing catalog key: {$key}");
            $this->assertNotSame(GovernanceMappingStatus::NotFound, $item->status, "{$key} must not be falsely NotFound — it is normalized/renamed/represented differently.");
        }
    }

    public function test_usage_attribution_json_is_covered_by_usage_rollups(): void
    {
        $item = $this->service->byKey('platform_invoices.usage_attribution_json');

        $this->assertSame(\App\Models\UsageRollup::class, $item->owning_class);
        $this->assertStringContainsString('usage_rollups', $item->notes);
    }

    /**
     * Low-stakes missing fields are honestly NotFound in the mapping,
     * but must never have been promoted to gap-register candidates.
     */
    public function test_low_stakes_missing_fields_are_not_found_but_not_gap_candidates(): void
    {
        $keys = [
            'document_templates.kind',
            'document_templates.review_rules_json',
            'form_edition_watch_items.authority',
            'form_edition_watch_items.current_edition',
            'form_edition_watch_items.sla_due_at',
            'form_edition_watch_items.action_taken',
        ];

        $gapKeys = array_map(fn ($g) => $g->item_key, $this->service->gaps());

        foreach ($keys as $key) {
            $item = $this->service->byKey($key);

            $this->assertNotNull($item, "Missing catalog key: {$key}");
            $this->assertSame(GovernanceMappingStatus::NotFound, $item->status, "{$key} should genuinely be NotFound.");
            $this->assertNotContains($key, $gapKeys, "{$key} must not be a gap-register candidate.");
        }
    }

    public function test_stripe_enabled_is_represented_via_entitlements_not_a_gap(): void
    {
        $item = $this->service->byKey('firm_settings.stripe_enabled');

        $this->assertNotSame(GovernanceMappingStatus::NotFound, $item->status);
        $this->assertStringContainsString('module_catalog', $item->notes);

        $gapKeys = array_map(fn ($g) => $g->item_key, $this->service->gaps());
        $this->assertNotContains('firm_settings.stripe_enabled', $gapKeys);
    }

    public function test_data_region_and_uuid_findings_are_not_gap_candidates(): void
    {
        $gapKeys = array_map(fn ($g) => $g->item_key, $this->service->gaps());

        $this->assertNotContains('firms.data_region', $gapKeys);
        $this->assertEmpty(array_filter($gapKeys, fn ($k) => str_contains($k, 'uuid')));
    }
}
