<?php

namespace Tests\Feature\Governance\DeploymentEnvironment;

use App\Enums\GovernanceMappingStatus;
use App\Services\OperationalReadinessMappingService;
use Tests\TestCase;

class OperationalReadinessMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'backups',
        'restore_testing',
        'monitoring',
        'alerting',
        'scheduler_health',
        'queue_health',
        'log_retention',
        'incident_process',
        'deployment_rollback',
        'fleet_migration_enrollment',
        'per_instance_status',
        'version_skew_one_minor_version',
        'integration_degradation_modes',
        'secure_secret_storage',
        'secret_rotation',
        'firm_owned_ai_api_key_encryption',
        'no_show_again_after_key_entry',
        'no_codebase_fork',
        'customization_surfaces_limited',
    ];

    private OperationalReadinessMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OperationalReadinessMappingService();
    }

    public function test_all_nineteen_operational_keys_are_declared_explicitly(): void
    {
        $items = $this->service->all();

        $this->assertCount(19, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required operational key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate operational key(s) found.');
    }

    public function test_backups_and_restore_testing_are_partially_implemented_readiness_only(): void
    {
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $this->service->byKey('backups')->status);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $this->service->byKey('restore_testing')->status);
    }

    public function test_deployment_rollback_is_implemented_bookkeeping_only(): void
    {
        $item = $this->service->byKey('deployment_rollback');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('bookkeeping', $item->notes);
        $this->assertStringContainsString('no real schema reversal', $item->notes);
    }

    public function test_version_skew_control_is_implemented(): void
    {
        $item = $this->service->byKey('version_skew_one_minor_version');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\VersionSkewPolicyService::class, $item->owning_class);
    }

    public function test_integration_degradation_modes_are_partially_implemented_because_ai_sms_whatsapp_undeclared(): void
    {
        $item = $this->service->byKey('integration_degradation_modes');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('AiProvider', $item->notes);
        $this->assertStringContainsString('Sms', $item->notes);
        $this->assertStringContainsString('WhatsApp', $item->notes);
    }

    public function test_firm_owned_ai_api_key_encryption_and_no_show_again_are_implemented(): void
    {
        $this->assertSame(GovernanceMappingStatus::Implemented, $this->service->byKey('firm_owned_ai_api_key_encryption')->status);
        $this->assertSame(GovernanceMappingStatus::Implemented, $this->service->byKey('no_show_again_after_key_entry')->status);
    }

    public function test_no_codebase_fork_is_not_falsely_claimed_as_fully_machine_proven(): void
    {
        $item = $this->service->byKey('no_codebase_fork');

        $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('Not a property any static scanner can fully machine-prove', $item->notes);
        $this->assertStringContainsString('not overclaimed', $item->notes);
    }

    public function test_customization_surfaces_limited_is_implemented_by_named_evidence(): void
    {
        $item = $this->service->byKey('customization_surfaces_limited');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('EntitlementService', $item->notes);
        $this->assertStringContainsString('TemplatePackVersion', $item->notes);
        $this->assertStringContainsString('WebhookSubscription', $item->notes);
        $this->assertStringContainsString('ApiKey', $item->notes);
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

        $this->assertCount(19, array_unique($union));
        $this->assertCount(19, $union, 'Buckets must not overlap.');
    }

    public function test_gaps_never_includes_an_implemented_item(): void
    {
        foreach ($this->service->gaps() as $item) {
            $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);
        }
    }
}
