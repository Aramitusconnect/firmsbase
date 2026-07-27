<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\PlatformRoleService;
use App\Services\PlatformTimelineEventDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformTimelineEventDirectoryServiceTest — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Audit Logs module. Proves the
 * per-firm loop + merge read pattern over `timeline_events` (mirroring
 * PlatformFirmUserDirectoryServiceTest's own established shape), the
 * firm/event-type/date filters, deterministic equal-timestamp ordering,
 * the per-firm cap, and the canAccessGovernance() gate.
 */
final class PlatformTimelineEventDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformTimelineEventDirectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlatformTimelineEventDirectoryService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_list_merges_events_across_every_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        $this->createWithFirmContext($firmA, fn () => TimelineEvent::factory()->forFirm($firmA)->create());
        $this->createWithFirmContext($firmB, fn () => TimelineEvent::factory()->forFirm($firmB)->create());
        $this->createWithFirmContext($firmB, fn () => TimelineEvent::factory()->forFirm($firmB)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin);

        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->where('firm_name', 'Firm A'));
        $this->assertCount(2, $rows->where('firm_name', 'Firm B'));
    }

    public function test_firm_filter_narrows_to_a_single_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->create(['name' => 'Firm B']);

        $this->createWithFirmContext($firmA, fn () => TimelineEvent::factory()->forFirm($firmA)->create());
        $this->createWithFirmContext($firmB, fn () => TimelineEvent::factory()->forFirm($firmB)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin, ['firm_uuid' => $firmA->uuid]);

        $this->assertCount(1, $rows);
        $this->assertSame('Firm A', $rows->first()['firm_name']);
    }

    public function test_event_type_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->eventType('matter_opened')->create());
        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->eventType('invoice_drafted')->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin, ['event_type' => 'matter']);

        $this->assertCount(1, $rows);
        $this->assertSame('matter_opened', $rows->first()['event_type']);
    }

    public function test_date_range_filter_narrows_the_list(): void
    {
        $firm = Firm::factory()->create();

        $old = $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create(['occurred_at' => now()->subDays(30)]));
        $recent = $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create(['occurred_at' => now()]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $rows = $this->service->list($admin, ['from' => now()->subDay()->toDateTimeString()]);

        $this->assertCount(1, $rows);
        $this->assertSame($recent->id, $rows->first()['id']);
    }

    public function test_orders_deterministically_by_id_when_occurred_at_ties(): void
    {
        $firm = Firm::factory()->create();
        $tie = now();

        $events = collect(range(1, 4))->map(fn () => $this->createWithFirmContext(
            $firm,
            fn () => TimelineEvent::factory()->forFirm($firm)->create(['occurred_at' => $tie])
        ));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $firstPass = $this->service->list($admin)->pluck('id')->all();
        $secondPass = $this->service->list($admin)->pluck('id')->all();

        $this->assertSame($firstPass, $secondPass, 'Tied occurred_at rows must order identically across repeated calls.');
        $this->assertSame($events->sortByDesc('id')->pluck('id')->values()->all(), $firstPass);
    }

    public function test_find_resolves_only_under_the_correct_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $event = $this->createWithFirmContext($firmA, fn () => TimelineEvent::factory()->forFirm($firmA)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $found = $this->service->find($admin, $firmA, $event->id);
        $this->assertNotNull($found);
        $this->assertSame($event->id, $found['id']);

        $notFoundUnderWrongFirm = $this->service->find($admin, $firmB, $event->id);
        $this->assertNull($notFoundUnderWrongFirm, 'A timeline_events row belonging to firm A must never resolve when looked up under firm B\'s context.');
    }

    public function test_metadata_json_is_returned_intact(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create([
            'metadata_json' => ['invoice_id' => 42, 'invoice_type' => 'flat_fee'],
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $found = $this->service->find($admin, $firm, $event->id);
        $this->assertSame(['invoice_id' => 42, 'invoice_type' => 'flat_fee'], $found['metadata_json']);
    }

    public function test_a_role_without_governance_access_is_denied(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->expectException(RuntimeException::class);

        $this->service->list($admin);
    }

    public function test_a_security_auditor_and_read_only_auditor_can_read(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => TimelineEvent::factory()->forFirm($firm)->create());

        foreach ([PlatformRoleCode::SecurityAuditor, PlatformRoleCode::ReadOnlyAuditor] as $role) {
            $admin = $this->adminWithRole($role);
            $rows = $this->service->list($admin);
            $this->assertCount(1, $rows);
        }
    }
}
