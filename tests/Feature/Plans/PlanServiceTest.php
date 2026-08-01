<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanStatus;
use App\Models\FirmLicense;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlanService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanService(app(TenantContextService::class));
    }

    public function test_create_defaults_to_draft_and_active_flag(): void
    {
        $plan = $this->service->create(['name' => 'Solo Practice', 'code' => 'solo-practice', 'price_cents' => 9900]);

        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertTrue($plan->is_active);
        $this->assertSame('Solo Practice', $plan->name);
        $this->assertSame('solo-practice', $plan->code);
    }

    public function test_create_without_a_code_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(['name' => 'Solo Practice', 'price_cents' => 9900]);
    }

    public function test_create_rejects_a_duplicate_code_case_insensitively(): void
    {
        Plan::factory()->create(['code' => 'solo-practice']);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create(['name' => 'Another Plan', 'code' => 'SOLO-PRACTICE', 'price_cents' => 1000]);
    }

    public function test_update_changes_editable_fields(): void
    {
        $plan = Plan::factory()->draft()->create(['name' => 'Old Name']);

        $updated = $this->service->update($plan, ['name' => 'New Name', 'price_cents' => 14900]);

        $this->assertSame('New Name', $updated->name);
        $this->assertSame(14900, $updated->price_cents);
    }

    public function test_update_refuses_to_change_price_once_a_firm_license_is_assigned(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 9900]);
        FirmLicense::factory()->create(['plan_id' => $plan->id]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->update($plan, ['price_cents' => 19900]);
    }

    public function test_update_still_allows_non_financial_fields_once_a_firm_license_is_assigned(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 9900]);
        FirmLicense::factory()->create(['plan_id' => $plan->id]);

        $updated = $this->service->update($plan, ['name' => 'Renamed While In Use']);

        $this->assertSame('Renamed While In Use', $updated->name);
    }

    public function test_activate_moves_draft_to_active(): void
    {
        $plan = Plan::factory()->draft()->create();

        $activated = $this->service->activate($plan);

        $this->assertSame(PlanStatus::Active, $activated->status);
    }

    public function test_archive_deactivates_the_plan(): void
    {
        $plan = Plan::factory()->create();

        $archived = $this->service->archive($plan);

        $this->assertSame(PlanStatus::Archived, $archived->status);
        $this->assertFalse($archived->is_active);
    }

    // ------------------------------------------------------------
    // Phase 3 FirmsVault Admin Control Center additions — actor +
    // audit plumbing on activate()/archive().
    // ------------------------------------------------------------

    public function test_activate_without_an_actor_writes_no_audit_event(): void
    {
        $plan = Plan::factory()->draft()->create();

        $this->service->activate($plan);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_activated')->count()
        );
        $this->assertSame(0, $count);
    }

    public function test_activate_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $plan = Plan::factory()->draft()->create();

        $activated = $this->service->activate($plan, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_activated')->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id);
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('platform_billing', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($activated->id, $metadata['plan_id']);
        $this->assertSame('active', $metadata['resulting_status']);
        $this->assertEqualsCanonicalizing(['plan_id', 'resulting_status'], array_keys($metadata));
    }

    public function test_archive_without_an_actor_writes_no_audit_event(): void
    {
        $plan = Plan::factory()->create();

        $this->service->archive($plan);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_archived')->count()
        );
        $this->assertSame(0, $count);
    }

    public function test_archive_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $plan = Plan::factory()->create();

        $archived = $this->service->archive($plan, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_archived')->first()
        );

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($archived->id, $metadata['plan_id']);
        $this->assertSame('archived', $metadata['resulting_status']);
    }
}
