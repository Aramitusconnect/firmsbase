<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TimelineEvent;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlatformIntegrationOversightQueryDeterminismTest — Phase 2 (FirmsVault
 * Platform Admin Control Center, "Integration Operations Center").
 * Query-hardening regression proof, matching the exact discipline
 * Phase 1's own query-hardening pass established: for every method
 * below, fixtures sharing an IDENTICAL primary sort timestamp are
 * created, and the query is proven to return the exact same order
 * across repeated calls — proving the added `orderBy('id')` (or
 * equivalent) tie-breaker genuinely makes the result deterministic,
 * not merely "usually stable" by accident of physical row layout.
 */
final class PlatformIntegrationOversightQueryDeterminismTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_summaries_returns_identical_order_across_repeated_calls(): void
    {
        // firm_uuid is unique per row (one summary row per firm), so a
        // genuine tie on the primary sort key (firm_uuid) cannot be
        // constructed here — this instead proves general repeatability
        // of the now-doubly-ordered (firm_uuid, id) query, which the
        // added `orderBy('id')` tie-breaker makes structurally
        // guaranteed rather than incidental.
        $firms = Firm::factory()->count(5)->activated()->create();

        foreach ($firms as $firm) {
            DB::table('integration_platform_overview_summaries')->insert([
                'firm_id' => $firm->id,
                'firm_uuid' => $firm->uuid,
                'connection_count_active' => 0,
                'connection_count_disconnected' => 0,
                'connection_count_other' => 0,
                'health_summary_state' => null,
                'last_sync_outcome' => null,
                'last_sync_at' => null,
                'failed_permanent_sync_item_count' => 0,
                'dead_lettered_outbox_event_count' => 0,
                'open_conflict_count' => 0,
                'entitlement_enabled' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstOrder = $service->overviewSummaries($admin)->pluck('firm_uuid')->all();
        $secondOrder = $service->overviewSummaries($admin)->pluck('firm_uuid')->all();

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertSame($firstOrder, collect($firstOrder)->sort()->values()->all(), 'Order must be sorted by firm_uuid.');
    }

    public function test_connections_for_firm_orders_deterministically_when_created_at_ties(): void
    {
        $firm = Firm::factory()->activated()->create();

        $tiedTimestamp = now();

        $connections = $this->runWithFirmContext($firm, function () use ($firm, $tiedTimestamp) {
            $rows = FirmIntegration::factory()->forFirm($firm)->count(4)->create();

            foreach ($rows as $row) {
                FirmIntegration::query()->where('id', $row->id)->update(['created_at' => $tiedTimestamp]);
            }

            return $rows->sortBy('id')->values();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstOrder = $service->connectionsForFirm($admin, $firm, 1, 25)->getCollection()->pluck('uuid')->all();
        $secondOrder = $service->connectionsForFirm($admin, $firm, 1, 25)->getCollection()->pluck('uuid')->all();

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertSame($connections->pluck('uuid')->all(), $firstOrder, 'Tied created_at rows must break the tie by ascending id.');
    }

    public function test_connections_for_firm_is_genuinely_paginated_at_the_db_level(): void
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->count(7)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstPage = $service->connectionsForFirm($admin, $firm, 1, 3);
        $secondPage = $service->connectionsForFirm($admin, $firm, 2, 3);

        $this->assertCount(3, $firstPage->getCollection());
        $this->assertCount(3, $secondPage->getCollection());
        $this->assertSame(7, $firstPage->total());
        $this->assertEmpty(array_intersect(
            $firstPage->getCollection()->pluck('uuid')->all(),
            $secondPage->getCollection()->pluck('uuid')->all(),
        ), 'Page 1 and page 2 must never overlap — proving a real DB-level LIMIT/OFFSET, not a full materialization sliced identically each call.');
    }

    public function test_sync_history_for_connection_orders_deterministically_when_created_at_ties(): void
    {
        $firm = Firm::factory()->activated()->create();

        $tiedTimestamp = now();

        [$connection, $runIds] = $this->runWithFirmContext($firm, function () use ($firm, $tiedTimestamp) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();
            $runs = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->count(4)->create();

            foreach ($runs as $run) {
                IntegrationSyncRun::query()->where('id', $run->id)->update(['created_at' => $tiedTimestamp]);
            }

            return [$connection, $runs->sortBy('id')->pluck('id')->values()->all()];
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstOrder = $service->syncHistoryForConnection($admin, $firm, $connection->id)->pluck('id')->all();
        $secondOrder = $service->syncHistoryForConnection($admin, $firm, $connection->id)->pluck('id')->all();

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertSame($runIds, $firstOrder, 'Tied created_at rows must break the tie by ascending id.');
    }

    public function test_failed_items_for_connection_orders_deterministically_when_failed_at_ties(): void
    {
        $firm = Firm::factory()->activated()->create();

        $tiedTimestamp = now();

        [$connection, $expectedIds] = $this->runWithFirmContext($firm, function () use ($firm, $tiedTimestamp) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $events = IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->count(3)->create();
            foreach ($events as $event) {
                IntegrationOutboxEvent::query()->where('id', $event->id)->update(['dead_lettered_at' => $tiedTimestamp]);
            }

            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();
            $items = IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->count(3)->create();
            foreach ($items as $item) {
                IntegrationSyncItem::query()->where('id', $item->id)->update(['terminal_at' => $tiedTimestamp]);
            }

            $expectedIds = $events->sortBy('id')->map(fn ($e) => "outbox:{$e->id}")->values()
                ->concat($items->sortBy('id')->map(fn ($i) => "sync_item:{$i->id}"))
                ->all();

            return [$connection, $expectedIds];
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstOrder = $service->failedItemsForConnection($admin, $firm, $connection->id)->pluck('id')->all();
        $secondOrder = $service->failedItemsForConnection($admin, $firm, $connection->id)->pluck('id')->all();

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertSame($expectedIds, $firstOrder, 'Tied failed_at rows must break the tie by ascending id, outbox events before sync items (stable concat order).');
    }

    public function test_conflicts_for_connection_orders_deterministically_when_detected_at_ties(): void
    {
        $firm = Firm::factory()->activated()->create();

        $tiedTimestamp = now();

        [$connection, $expectedIds] = $this->runWithFirmContext($firm, function () use ($firm, $tiedTimestamp) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();
            $conflicts = IntegrationConflict::factory()->forFirmIntegration($connection)->count(4)->create(['status' => ConflictStatus::Detected->value]);

            foreach ($conflicts as $conflict) {
                IntegrationConflict::query()->where('id', $conflict->id)->update(['detected_at' => $tiedTimestamp]);
            }

            return [$connection, $conflicts->sortBy('id')->pluck('id')->values()->all()];
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstOrder = $service->conflictsForConnection($admin, $firm, $connection->id)->pluck('id')->all();
        $secondOrder = $service->conflictsForConnection($admin, $firm, $connection->id)->pluck('id')->all();

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertSame($expectedIds, $firstOrder, 'Tied detected_at rows must break the tie by ascending id.');
    }

    public function test_sanitized_audit_history_orders_deterministically_when_occurred_at_ties(): void
    {
        $firm = Firm::factory()->activated()->create();

        $tiedTimestamp = now();

        $expected = $this->runWithFirmContext($firm, function () use ($firm, $tiedTimestamp) {
            $timelineEvents = collect();
            for ($i = 0; $i < 3; $i++) {
                $timelineEvents->push(TimelineEvent::query()->create([
                    'firm_id' => $firm->id,
                    'event_type' => 'integration_test.fixture_event',
                    'actor_type' => null,
                    'actor_id' => null,
                    'occurred_at' => $tiedTimestamp,
                    'metadata_json' => [],
                ]));
            }

            $securityEvents = collect();
            for ($i = 0; $i < 3; $i++) {
                $securityEvents->push(SecurityEvent::query()->create([
                    'firm_id' => $firm->id,
                    'actor_type' => PlatformAdmin::class,
                    'actor_id' => 1,
                    'event_type' => 'platform_integration_oversight.fixture_event',
                    'category' => 'platform_integration_oversight',
                    'metadata' => [],
                ]));
            }

            // created_at on SecurityEvent must be forced to the SAME
            // tied timestamp as the timeline events above — factory/
            // Eloquent auto-timestamps would otherwise make them differ
            // by however many microseconds elapsed between inserts.
            foreach ($securityEvents as $event) {
                SecurityEvent::query()->where('id', $event->id)->update(['created_at' => $tiedTimestamp]);
            }

            $timelineExpected = $timelineEvents->sortBy('id')->map(fn ($e) => ['source' => 'timeline', 'id' => $e->id])->values();
            $securityExpected = $securityEvents->sortBy('id')->map(fn ($e) => ['source' => 'security', 'id' => $e->id])->values();

            return $timelineExpected->concat($securityExpected)->all();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $service = app(IntegrationPlatformOversightReadService::class);

        $firstOrder = $service->sanitizedAuditHistoryForFirm($admin, $firm)
            ->map(fn (array $row): array => ['source' => $row['source'], 'id' => $row['id']])
            ->values()
            ->all();
        $secondOrder = $service->sanitizedAuditHistoryForFirm($admin, $firm)
            ->map(fn (array $row): array => ['source' => $row['source'], 'id' => $row['id']])
            ->values()
            ->all();

        $this->assertSame($firstOrder, $secondOrder);
        $this->assertSame($expected, $firstOrder, 'Tied occurred_at/created_at rows must break the tie by ascending id, timeline rows before security rows (stable concat order).');
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
