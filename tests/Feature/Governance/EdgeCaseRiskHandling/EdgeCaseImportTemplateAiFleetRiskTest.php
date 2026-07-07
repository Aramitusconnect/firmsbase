<?php

namespace Tests\Feature\Governance\EdgeCaseRiskHandling;

use App\Enums\GovernanceMappingStatus;
use App\Services\EdgeCaseRiskCatalogMappingService;
use Tests\TestCase;

class EdgeCaseImportTemplateAiFleetRiskTest extends TestCase
{
    private EdgeCaseRiskCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EdgeCaseRiskCatalogMappingService();
    }

    public function test_import_bad_data_blocks_production_writes_before_preview_validation_confirmation(): void
    {
        $item = $this->service->byKey('import_bad_data');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('ONLY place production records are created', $item->notes);
        $this->assertStringContainsString('confirmBatch()', $item->notes);
    }

    public function test_template_upgrade_active_matters_preserves_pinned_versions(): void
    {
        $item = $this->service->byKey('template_upgrade_active_matters');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('never changed afterward', $item->notes);
        $this->assertStringContainsString('never retroactively touches', $item->notes);
    }

    public function test_form_edition_retired_classification_and_cross_reference_is_clear(): void
    {
        $item = $this->service->byKey('form_edition_retired');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('form_edition_watch_sla_controls_missing', $item->notes);
        $this->assertStringContainsString('not a duplicate', $item->notes);
    }

    public function test_prompt_injection_uploaded_pdf_treats_document_text_as_untrusted_and_logs_readiness(): void
    {
        $item = $this->service->byKey('prompt_injection_uploaded_pdf');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\PromptInjectionResistanceService::class, $item->owning_class);
        $this->assertStringContainsString('wrapAsUntrustedData', $item->notes);
        $this->assertStringContainsString('was_constrained', $item->notes);
    }

    public function test_fleet_migration_failure_mid_rollout_halt_rollback_skew_behavior_is_mapped(): void
    {
        $item = $this->service->byKey('fleet_migration_failure_mid_rollout');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('Halted', $item->notes);
        $this->assertStringContainsString('rollback()', $item->notes);
        $this->assertStringContainsString('VersionSkewPolicyService', $item->notes);
    }

    public function test_trust_overdraft_concurrent_requests_cross_references_without_duplicating(): void
    {
        $item = $this->service->byKey('trust_overdraft_concurrent_requests');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('trust_ledger_entry_posting_actor_not_guaranteed', $item->notes);
        $this->assertStringContainsString('not a duplicate', $item->notes);
    }
}
