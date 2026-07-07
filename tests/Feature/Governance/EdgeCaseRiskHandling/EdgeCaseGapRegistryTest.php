<?php

namespace Tests\Feature\Governance\EdgeCaseRiskHandling;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * EdgeCaseGapRegistryTest — proves Section 35 added exactly the three
 * AWS-confirmed gaps to the EXISTING ComplianceGapRegistryService
 * (18 -> 21), without duplicating emergency_support_access_high_risk_approval_not_wired,
 * form_edition_watch_sla_controls_missing, rls_prepared_not_enforced,
 * or trust_ledger_entry_posting_actor_not_guaranteed.
 */
class EdgeCaseGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_section_34_gap_count_before_section_35_additions_was_eighteen(): void
    {
        // 18 pre-existing (Section 25-34) + 3 new Section 35 gaps
        // (confirmed) = 21.
        $this->assertCount(21, $this->service->all());
    }

    public function test_final_gap_count_equals_eighteen_plus_confirmed_new_gaps(): void
    {
        $this->assertCount(21, $this->service->all());
    }

    public function test_confirmed_new_gap_keys_exist_with_approved_severities(): void
    {
        $aiGap = $this->service->byKey('ai_jobs_not_cancelled_when_entitlement_removed');
        $this->assertNotNull($aiGap);
        $this->assertSame(GovernanceGapSeverity::Medium, $aiGap->severity);

        $languageGap = $this->service->byKey('template_language_fallback_staff_notification_missing');
        $this->assertNotNull($languageGap);
        $this->assertSame(GovernanceGapSeverity::Low, $languageGap->severity);

        $stripeGap = $this->service->byKey('stripe_disconnect_payment_collection_block_not_enforced');
        $this->assertNotNull($stripeGap);
        $this->assertSame(GovernanceGapSeverity::Medium, $stripeGap->severity);
    }

    public function test_no_duplicate_emergency_support_form_edition_or_trust_ledger_gaps_were_added(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(1, array_filter($keys, fn (string $key) => str_contains($key, 'emergency_support_access')));
        $this->assertCount(1, array_filter($keys, fn (string $key) => str_contains($key, 'form_edition_watch_sla')));
        $this->assertCount(1, array_filter($keys, fn (string $key) => str_contains($key, 'rls_prepared_not_enforced')));
        $this->assertCount(1, array_filter($keys, fn (string $key) => str_contains($key, 'trust_ledger_entry_posting_actor')));
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }
}
