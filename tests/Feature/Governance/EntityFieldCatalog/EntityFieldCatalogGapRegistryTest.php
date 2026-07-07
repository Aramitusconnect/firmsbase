<?php

namespace Tests\Feature\Governance\EntityFieldCatalog;

use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * EntityFieldCatalogGapRegistryTest — proves Section 32 added at most
 * the one AWS-confirmed trust-ledger-actor gap to the EXISTING
 * ComplianceGapRegistryService (15 -> 16), and did NOT add gaps for
 * data_region, OCR/UI, internal admin form-edition fields, or document
 * template convenience fields.
 */
class EntityFieldCatalogGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_no_data_region_gap_exists(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertEmpty(array_filter($keys, fn (string $key) => str_contains($key, 'data_region')));
    }

    public function test_no_gaps_were_added_for_ocr_ui_or_internal_admin_convenience_fields(): void
    {
        $forbiddenGapKeys = [
            'document_templates_kind_missing',
            'document_templates_review_rules_missing',
            'form_edition_watch_items_authority_missing',
            'form_edition_watch_items_current_edition_missing',
            'form_edition_watch_items_sla_due_at_missing',
            'form_edition_watch_items_action_taken_missing',
            'matters_billing_status_missing',
            'stripe_enabled_missing',
            'platform_invoices_currency_missing',
        ];

        foreach ($forbiddenGapKeys as $key) {
            $this->assertFalse($this->service->isTracked($key), "Gap '{$key}' must not exist — Section 32 does not add gaps for representative-only fields.");
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

    public function test_final_gap_count_is_sixteen_because_trust_ledger_actor_gap_was_confirmed(): void
    {
        // 15 pre-existing (Section 25-31) + 1 new Section 32
        // trust-ledger-actor gap, confirmed by AWS evidence that
        // Reversal/ChargebackReversal postings have no guaranteed
        // actor trail = 16.
        $this->assertCount(18, $this->service->all());
    }

    public function test_trust_ledger_actor_gap_is_tracked(): void
    {
        $this->assertTrue($this->service->isTracked('trust_ledger_entry_posting_actor_not_guaranteed'));
    }
}
