<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Services\PlatformAdminAuditEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformAdminAuditEventRecorderTest — Phase 1 FirmsVault Admin
 * Control Center. Proves the new generic security_events writer
 * produces the exact same row shape as the 3 existing hand-rolled call
 * sites it was modeled on (firm_id/actor_type=PlatformAdmin::class/
 * actor_id/event_type/category/metadata/created_at), and that it
 * correctly establishes the app.current_firm_id context
 * security_events' FORCE RLS write policy requires (an unwrapped
 * insert would be rejected outright).
 */
class PlatformAdminAuditEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_a_correctly_shaped_security_events_row(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        (new PlatformAdminAuditEventRecorder)->record(
            $firm,
            $admin,
            'platform_admin.test_event',
            'test_category',
            ['foo' => 'bar'],
        );

        $row = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_admin.test_event')
            ->first());

        $this->assertNotNull($row, 'The security_events row must exist under its own firm context.');
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('test_category', $row->category);
        $this->assertSame(['foo' => 'bar'], $row->metadata);
    }

    public function test_record_restores_ambient_context_afterward(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->assertNoDatabaseTenantContext();

        (new PlatformAdminAuditEventRecorder)->record($firm, $admin, 'platform_admin.test_event', 'test_category');

        $this->assertNoDatabaseTenantContext('runWithFirmContext() must restore the prior (empty) context after record() returns.');
    }

    public function test_a_row_written_for_one_firm_is_invisible_under_another_firms_context(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        (new PlatformAdminAuditEventRecorder)->record($firmA, $admin, 'platform_admin.test_event', 'test_category');

        $rowUnderFirmB = $this->runWithFirmContext($firmB, fn () => SecurityEvent::query()
            ->where('event_type', 'platform_admin.test_event')
            ->first());

        $this->assertNull($rowUnderFirmB, 'firm_id-scoped RLS must prevent firm B\'s context from seeing firm A\'s event.');
    }
}
