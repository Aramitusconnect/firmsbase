<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Data\SanitizedUsageMetadataReference;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Services\IntegrationUsageRecorderService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * IntegrationUsageRecorderServiceTest — Checkpoint 9
 * (frozen-design-post-security-review.md §2). Proves: idempotency
 * (INSERT ... ON CONFLICT DO NOTHING, writing the same idempotency key
 * twice produces exactly one row, no exception, the SAME row is
 * returned both times); retention_deadline null when the config key is
 * unset and correctly computed when it is set; no billing/cost field
 * exists anywhere in the write path or schema; SanitizedUsageMetadataReference
 * actually gates what lands in metadata_json (a disallowed shape is
 * rejected by the DTO's own constructor before it can ever reach
 * recordOnce()).
 */
class IntegrationUsageRecorderServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationUsageRecorderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationUsageRecorderService();
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    // ------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------

    public function test_writing_the_same_idempotency_key_twice_produces_exactly_one_row_and_no_exception(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $first = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: 'test',
            capability: 'sync',
            operationType: 'pull_sync',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Contact,
            unit: 'item',
            outcome: 'success',
            idempotencyKey: 'sync_item:42',
        ));

        $second = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id,
            firmIntegrationId: $connection->id,
            providerKey: 'test',
            capability: 'sync',
            operationType: 'pull_sync',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Contact,
            unit: 'item',
            outcome: 'success',
            idempotencyKey: 'sync_item:42',
        ));

        $this->assertInstanceOf(IntegrationUsageRecord::class, $first);
        $this->assertInstanceOf(IntegrationUsageRecord::class, $second);
        $this->assertSame($first->id, $second->id, 'A duplicate idempotency key must return the SAME durable row, never a second one.');

        $count = $this->runWithFirmContext($firm, fn () => DB::table('integration_usage_records')
            ->where('firm_integration_id', $connection->id)
            ->where('idempotency_key', 'sync_item:42')
            ->count());

        $this->assertSame(1, $count, 'Exactly one row must exist for this idempotency key, regardless of how many times recordOnce() is called with it.');
    }

    public function test_the_same_idempotency_key_under_two_different_firm_integrations_of_the_same_firm_produces_two_rows(): void
    {
        $firm = Firm::factory()->create();
        $connectionA = $this->connection($firm);
        $connectionB = $this->connection($firm);

        $recordA = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id, firmIntegrationId: $connectionA->id, providerKey: 'test', capability: 'sync',
            operationType: 'pull_sync', direction: SyncDirection::Inbound, resourceType: null, unit: 'item',
            outcome: 'success', idempotencyKey: 'sync_item:same-key',
        ));

        $recordB = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id, firmIntegrationId: $connectionB->id, providerKey: 'test', capability: 'sync',
            operationType: 'pull_sync', direction: SyncDirection::Inbound, resourceType: null, unit: 'item',
            outcome: 'success', idempotencyKey: 'sync_item:same-key',
        ));

        $this->assertNotSame($recordA->id, $recordB->id, 'The idempotency constraint is keyed on firm_integration_id + idempotency_key, not firm_id alone.');
    }

    public function test_derive_idempotency_key_produces_the_frozen_source_type_colon_source_id_shape(): void
    {
        $this->assertSame('sync_item:42', $this->service->deriveIdempotencyKey('sync_item', '42'));
        $this->assertSame('outbox_event:7:webhook_process', $this->service->deriveIdempotencyKey('outbox_event', '7', 'webhook_process'));
    }

    // ------------------------------------------------------------
    // retention_deadline: null when unset, computed when set
    // ------------------------------------------------------------

    public function test_retention_deadline_is_null_when_the_config_key_is_unset(): void
    {
        $this->assertNull(
            config('integrations.usage_records.retention_days'),
            'Sanity check: this key must genuinely be unset for this test to prove anything.'
        );

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $record = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id, firmIntegrationId: $connection->id, providerKey: 'test', capability: 'sync',
            operationType: 'pull_sync', direction: SyncDirection::Inbound, resourceType: null, unit: 'item',
            outcome: 'success', idempotencyKey: 'sync_item:unset-retention',
        ));

        $this->assertNull($record->retention_deadline);
    }

    public function test_retention_deadline_is_computed_correctly_once_the_config_key_is_explicitly_set(): void
    {
        config(['integrations.usage_records.retention_days' => 30]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $before = now();
        $record = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id, firmIntegrationId: $connection->id, providerKey: 'test', capability: 'sync',
            operationType: 'pull_sync', direction: SyncDirection::Inbound, resourceType: null, unit: 'item',
            outcome: 'success', idempotencyKey: 'sync_item:set-retention',
        ));

        $this->assertNotNull($record->retention_deadline);
        $this->assertTrue($record->retention_deadline->between($before->copy()->addDays(30)->subMinute(), $before->copy()->addDays(30)->addMinute()));
    }

    // ------------------------------------------------------------
    // No billing/cost field
    // ------------------------------------------------------------

    public function test_no_billing_or_cost_column_exists_on_the_table(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('integration_usage_records');

        foreach (['cost', 'cost_cents', 'billing', 'price', 'amount', 'charge'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "integration_usage_records must not carry a billing/cost column: {$forbidden}");
        }
    }

    public function test_recordonce_signature_has_no_cost_or_billing_parameter(): void
    {
        $reflection = new \ReflectionMethod(IntegrationUsageRecorderService::class, 'recordOnce');
        $paramNames = array_map(fn (\ReflectionParameter $p) => strtolower($p->getName()), $reflection->getParameters());

        foreach (['cost', 'costcents', 'billing', 'price', 'amount', 'charge'] as $forbidden) {
            $this->assertNotContains($forbidden, $paramNames, "recordOnce() must not accept a billing/cost-shaped parameter: {$forbidden}");
        }
    }

    // ------------------------------------------------------------
    // SanitizedUsageMetadataReference gates metadata_json
    // ------------------------------------------------------------

    public function test_recordonce_only_accepts_a_sanitizedusagemetadatareference_for_metadata(): void
    {
        $reflection = new \ReflectionMethod(IntegrationUsageRecorderService::class, 'recordOnce');
        $metadataParam = null;

        foreach ($reflection->getParameters() as $param) {
            if ($param->getName() === 'metadata') {
                $metadataParam = $param;
            }
        }

        $this->assertNotNull($metadataParam);
        $type = $metadataParam->getType();
        $this->assertNotNull($type);
        $this->assertStringContainsString(SanitizedUsageMetadataReference::class, (string) $type);
    }

    public function test_a_sanitized_metadata_reference_with_only_scalar_fields_is_accepted_and_persisted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $metadata = new SanitizedUsageMetadataReference(['byte_count' => 1024, 'rate_limit_bucket' => 'default']);

        $record = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id, firmIntegrationId: $connection->id, providerKey: 'test', capability: 'sync',
            operationType: 'pull_sync', direction: SyncDirection::Inbound, resourceType: null, unit: 'item',
            outcome: 'success', idempotencyKey: 'sync_item:metadata-ok', metadata: $metadata,
        ));

        $this->assertSame(['byte_count' => 1024, 'rate_limit_bucket' => 'default'], $record->metadata_json);
    }

    public function test_a_disallowed_object_shaped_metadata_value_is_rejected_at_dto_construction_before_it_can_ever_reach_recordonce(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SanitizedUsageMetadataReference(['bad_field' => new \stdClass()]);
    }

    public function test_a_disallowed_nested_object_inside_an_array_field_is_also_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SanitizedUsageMetadataReference(['nested' => ['ok' => 1, 'bad' => new \stdClass()]]);
    }

    public function test_metadata_defaults_to_an_empty_array_when_omitted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $record = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce(
            firmId: $firm->id, firmIntegrationId: $connection->id, providerKey: 'test', capability: 'sync',
            operationType: 'pull_sync', direction: SyncDirection::Inbound, resourceType: null, unit: 'item',
            outcome: 'success', idempotencyKey: 'sync_item:no-metadata',
        ));

        $this->assertSame([], $record->metadata_json);
    }

    public function test_metadata_never_accepts_a_raw_model_toarray_dump(): void
    {
        // Structural proof, not merely behavioral: recordOnce()'s own
        // signature cannot be satisfied by a raw array (e.g.
        // $model->toArray()) or an Eloquent Model — only a
        // SanitizedUsageMetadataReference instance type-checks.
        $reflection = new \ReflectionMethod(IntegrationUsageRecorderService::class, 'recordOnce');

        foreach ($reflection->getParameters() as $param) {
            if ($param->getName() === 'metadata') {
                $this->assertFalse($param->getType()?->allowsNull() === false, 'metadata must be nullable (optional), but its non-null branch must be the sanitized DTO only.');
                $typeName = (string) $param->getType();
                $this->assertStringNotContainsString('array', strtolower($typeName));
                $this->assertStringNotContainsString('Model', $typeName);
            }
        }
    }
}
