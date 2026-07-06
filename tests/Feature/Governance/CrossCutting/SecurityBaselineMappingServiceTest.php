<?php

namespace Tests\Feature\Governance\CrossCutting;

use App\Enums\GovernanceMappingStatus;
use App\Services\SecurityBaselineMappingService;
use Tests\TestCase;

class SecurityBaselineMappingServiceTest extends TestCase
{
    private const REQUIRED_ITEM_KEYS = [
        'tenant_isolation_query_policy_api_storage',
        'database_rls_defense_in_depth',
        'tenancy_single_resolver',
        'context_consumers_queries_storage_cache_queue_search',
        'per_firm_envelope_encryption',
        'phase17_key_destruction_governance',
        'firm_user_2fa',
        'client_portal_2fa',
        'csrf_protection',
        'secure_cookies',
        'session_timeout',
        'login_rate_limits',
        'password_rules',
        'suspicious_login_events',
        'private_file_storage',
        'no_public_legal_document_urls',
        'signed_temporary_urls_only_when_needed',
        'signed_urls_tenant_context_authorized_users',
        'malware_scanning_before_document_acceptance',
        'ai_retrieval_isolation_per_firm',
        'ai_matter_permission_enforcement',
        'prompt_injection_resistance',
        'audit_logging_required_categories',
        'reason_required_time_limited_support_access',
        'two_person_approval_high_risk_key_destruction',
    ];

    private SecurityBaselineMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SecurityBaselineMappingService();
    }

    public function test_all_twenty_five_items_are_declared(): void
    {
        $items = $this->service->all();

        $this->assertCount(25, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_ITEM_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required security-baseline item: {$key}");
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }

    public function test_every_declared_item_is_resolvable_by_key(): void
    {
        foreach (self::REQUIRED_ITEM_KEYS as $key) {
            $item = $this->service->byKey($key);

            $this->assertNotNull($item, "byKey() could not resolve: {$key}");
            $this->assertSame($key, $item->item_key);
        }
    }

    public function test_rls_is_classified_prepared_not_enforced(): void
    {
        $item = $this->service->byKey('database_rls_defense_in_depth');

        $this->assertSame(GovernanceMappingStatus::PreparedNotEnforced, $item->status);
    }

    public function test_firm_user_2fa_is_classified_not_found(): void
    {
        $item = $this->service->byKey('firm_user_2fa');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
    }

    public function test_client_portal_2fa_is_classified_not_found(): void
    {
        $item = $this->service->byKey('client_portal_2fa');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
    }

    public function test_signed_url_service_gap_is_classified_not_found(): void
    {
        $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey('signed_temporary_urls_only_when_needed')->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey('signed_urls_tenant_context_authorized_users')->status);
    }

    public function test_malware_scanner_is_partially_implemented_not_implemented(): void
    {
        $item = $this->service->byKey('malware_scanning_before_document_acceptance');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);

        // FakeVirusScanner must still be the only VirusScanner implementation
        // for this classification to remain accurate.
        $this->assertSame(
            \App\Services\VirusScan\FakeVirusScanner::class,
            $item->owning_class,
        );
    }

    public function test_audit_logging_item_reuses_the_existing_audit_preservation_service(): void
    {
        $item = $this->service->byKey('audit_logging_required_categories');

        $this->assertSame(\App\Services\AuditPreservationPolicyService::class, $item->owning_class);
    }

    public function test_gaps_never_includes_an_implemented_item(): void
    {
        foreach ($this->service->gaps() as $item) {
            $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);
        }
    }

    public function test_implemented_only_includes_implemented_items(): void
    {
        foreach ($this->service->implemented() as $item) {
            $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        }
    }

    public function test_gaps_and_implemented_partition_all_items_with_no_overlap(): void
    {
        $gapKeys = array_map(fn ($item) => $item->item_key, $this->service->gaps());
        $implementedKeys = array_map(fn ($item) => $item->item_key, $this->service->implemented());

        $this->assertEmpty(array_intersect($gapKeys, $implementedKeys));
        $this->assertCount(25, array_unique(array_merge($gapKeys, $implementedKeys)));
    }
}
