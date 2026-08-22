<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ExternalMappingConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IntegrationExternalMappingServiceTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §6;
 * agent-6h-test-plan-and-review.md §6 item 11). Both-direction
 * uniqueness at the SERVICE layer (recordMapping()'s own recovery
 * behavior, distinct from the raw-DB constraint-firing proofs in
 * IntegrationExternalMappingsForceRlsActivationTest.php): idempotent
 * return on a same-external-id re-map, a genuine
 * ExternalMappingConflictException on a same-local-different-external
 * conflict, the same-firm/two-connection hard case, and the
 * tombstone-then-remap escape valve.
 */
class IntegrationExternalMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationExternalMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationExternalMappingService;
    }

    // ------------------------------------------------------------
    // (a) same connection, duplicate external_id — idempotent, not an
    // exception: the SECOND call's differing local pointer is silently
    // superseded by the already-live mapping, mirroring firstOrCreate()
    // semantics for the same external object (agent-6e §15).
    // ------------------------------------------------------------

    public function test_recording_the_same_external_id_twice_on_the_same_connection_returns_the_existing_mapping(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $externalId = (string) Str::uuid();

        $first = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 1, $externalId, SyncDirection::Inbound),
        );

        $second = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 2, $externalId, SyncDirection::Inbound),
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->local_id, 'The already-live mapping (local_id=1) must be returned, not a second row for local_id=2.');

        $count = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationExternalMapping::query()->where('external_id', $externalId)->count(),
        );
        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------
    // (b) same connection, duplicate local_type/local_id for a
    // DIFFERENT external_id — a genuine data-integrity conflict, never
    // silently swallowed.
    // ------------------------------------------------------------

    public function test_recording_a_different_external_id_for_an_already_mapped_local_record_throws(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 55, (string) Str::uuid(), SyncDirection::Inbound),
        );

        $this->expectException(ExternalMappingConflictException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 55, (string) Str::uuid(), SyncDirection::Inbound),
        );
    }

    public function test_the_conflict_exception_carries_the_offending_identifiers(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $newExternalId = (string) Str::uuid();

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 77, (string) Str::uuid(), SyncDirection::Inbound),
        );

        try {
            $this->runWithFirmContext(
                $firm,
                fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 77, $newExternalId, SyncDirection::Inbound),
            );
            $this->fail('Expected ExternalMappingConflictException.');
        } catch (ExternalMappingConflictException $e) {
            $this->assertSame('App\\Models\\Contact', $e->localType);
            $this->assertSame(77, $e->localId);
            $this->assertSame($newExternalId, $e->externalId);
        }
    }

    // ------------------------------------------------------------
    // (c) the hard case — two connections of the SAME firm, identical
    // external_id, both succeed independently.
    // ------------------------------------------------------------

    public function test_two_connections_of_the_same_firm_can_each_independently_map_the_same_external_id(): void
    {
        $firm = Firm::factory()->create();
        $connectionOne = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionTwo = FirmIntegration::factory()->forFirm($firm)->create();
        $sharedExternalId = (string) Str::uuid();

        $mappingOne = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connectionOne, 'contact', 'App\\Models\\Contact', 901, $sharedExternalId, SyncDirection::Inbound),
        );

        $mappingTwo = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connectionTwo, 'contact', 'App\\Models\\Contact', 902, $sharedExternalId, SyncDirection::Inbound),
        );

        $this->assertNotSame($mappingOne->id, $mappingTwo->id);
        $this->assertSame($sharedExternalId, $mappingOne->external_id);
        $this->assertSame($sharedExternalId, $mappingTwo->external_id);
        $this->assertSame($connectionOne->id, $mappingOne->firm_integration_id);
        $this->assertSame($connectionTwo->id, $mappingTwo->firm_integration_id);
    }

    // ------------------------------------------------------------
    // (d) tombstone-then-remap succeeds
    // ------------------------------------------------------------

    public function test_tombstoning_a_mapping_then_remapping_the_same_pair_creates_a_fresh_live_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $externalId = (string) Str::uuid();

        $original = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 33, $externalId, SyncDirection::Inbound),
        );

        $tombstoned = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->tombstone($original, 'external_deleted'),
        );
        $this->assertNotNull($tombstoned->tombstoned_at);
        $this->assertSame('external_deleted', $tombstoned->tombstone_reason);

        $remapped = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 33, $externalId, SyncDirection::Inbound),
        );

        $this->assertNotSame($original->id, $remapped->id, 'A fresh live row must be created, not the tombstoned one revived.');
        $this->assertNull($remapped->tombstoned_at);
        $this->assertSame($externalId, $remapped->external_id);
        $this->assertSame(33, $remapped->local_id);
    }

    public function test_tombstone_never_hard_deletes_the_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $mapping = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->recordMapping($connection, 'contact', 'App\\Models\\Contact', 44, (string) Str::uuid(), SyncDirection::Inbound),
        );

        $this->runWithFirmContext($firm, fn () => $this->service->tombstone($mapping, 'local_deleted'));

        $stillExists = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationExternalMapping::query()->find($mapping->id),
        );

        $this->assertNotNull($stillExists, 'Tombstoning must never hard-delete the row — it survives for audit.');
    }
}
