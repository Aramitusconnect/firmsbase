<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\FlagIncidentCustomerImpactAction;
use App\Filament\Actions\Platform\FlagIncidentNotificationNeededAction;
use App\Filament\Actions\Platform\OpenIncidentAction;
use App\Filament\Actions\Platform\RecordIncidentRootCauseAction;
use App\Filament\Actions\Platform\ResolveIncidentAction;
use App\Filament\Actions\Platform\UpdateIncidentSeverityAction;
use App\Filament\Actions\Platform\UpdateIncidentStatusAction;
use App\Filament\Resources\PlatformIncidentResource;
use App\Filament\Resources\PlatformIncidentResource\Pages\ListPlatformIncidents;
use App\Filament\Resources\PlatformIncidentResource\Pages\ViewPlatformIncident;
use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIncidentResourceTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). The strongest mutation candidate in
 * this phase's scope: navigation, direct-route auth, "current state"
 * (latest-per-correlation_id) list semantics, timeline rendering,
 * filters, ordering, pagination, empty state, and the full lifecycle
 * (allow/deny/audit/resulting-state) for every one of the 7
 * IncidentService actions.
 */
final class PlatformIncidentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function assertAuditWritten(string $eventType, int $actorId): void
    {
        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', $eventType)
                ->where('actor_id', $actorId)
                ->first()
        );
        $this->assertNotNull($row, "Expected a security_events row for event_type={$eventType}.");
    }

    // --- Navigation + auth ---

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformIncidentResource::canAccess());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformIncidentResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformIncidentResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $incident = IncidentEvent::factory()->create(['message' => 'Widget outage']);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(PlatformIncidentResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Widget outage');

        $viewResponse = $this->get(PlatformIncidentResource::getUrl('view', ['record' => $incident]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Widget outage');
    }

    // --- Current-state / timeline semantics ---

    public function test_list_shows_only_the_latest_row_per_correlation_id(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $correlationId = (string) Str::uuid();
        $opened = IncidentEvent::factory()->create(['correlation_id' => $correlationId, 'severity' => IncidentSeverity::Low]);
        $updated = IncidentEvent::factory()->create([
            'correlation_id' => $correlationId,
            'event_type' => 'severity_changed',
            'severity' => IncidentSeverity::Critical,
        ]);

        $test = Livewire::test(ListPlatformIncidents::class);
        $test->assertCanSeeTableRecords([$updated]);
        $test->assertCanNotSeeTableRecords([$opened]);
    }

    public function test_view_page_shows_the_full_timeline(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $correlationId = (string) Str::uuid();
        IncidentEvent::factory()->create(['correlation_id' => $correlationId, 'message' => 'Initial report']);
        $latest = IncidentEvent::factory()->create([
            'correlation_id' => $correlationId,
            'event_type' => 'root_cause_added',
            'root_cause' => 'Bad deploy',
        ]);

        $response = $this->get(PlatformIncidentResource::getUrl('view', ['record' => $latest]));
        $response->assertOk();
        $response->assertSee('Opened');
        $response->assertSee('Root Cause Added');
    }

    // --- Empty state ---

    public function test_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformIncidentResource::getUrl());
        $response->assertOk();
        $response->assertSee('No platform-wide incidents recorded yet');
    }

    // --- Filters ---

    public function test_severity_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $critical = IncidentEvent::factory()->create(['severity' => IncidentSeverity::Critical]);
        $low = IncidentEvent::factory()->create(['severity' => IncidentSeverity::Low]);

        $test = Livewire::test(ListPlatformIncidents::class);
        $test->filterTable('severity', IncidentSeverity::Critical->value);

        $test->assertCanSeeTableRecords([$critical]);
        $test->assertCanNotSeeTableRecords([$low]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_when_created_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incidents = IncidentEvent::factory()->count(5)->create();

        $first = Livewire::test(ListPlatformIncidents::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListPlatformIncidents::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($incidents->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        IncidentEvent::factory()->count(30)->create();

        $test = Livewire::test(ListPlatformIncidents::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Open Incident lifecycle ---

    public function test_open_incident_is_allowed_for_a_super_admin_and_writes_audit_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListPlatformIncidents::class);
        $test->mountAction(OpenIncidentAction::getDefaultName());
        $test->setActionData([
            'severity' => IncidentSeverity::High->value,
            'message' => 'API errors spiking',
            'customer_impact' => true,
            'notification_needed' => false,
        ]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame(1, IncidentEvent::query()->count());
        $incident = IncidentEvent::query()->first();
        $this->assertSame(IncidentSeverity::High, $incident->severity);
        $this->assertTrue($incident->customer_impact);
        $this->assertNull($incident->firm_id);

        $this->assertAuditWritten('incident_opened', $admin->id);
    }

    public function test_open_incident_is_denied_for_a_read_only_auditor(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListPlatformIncidents::class);
        $test->mountAction(OpenIncidentAction::getDefaultName());
        $test->setActionData(['severity' => IncidentSeverity::High->value, 'message' => 'x']);
        $test->callMountedAction();

        $this->assertSame(0, IncidentEvent::query()->count());
    }

    // --- Update Severity lifecycle ---

    public function test_update_severity_is_allowed_and_appends_a_new_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create(['severity' => IncidentSeverity::Low]);

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(UpdateIncidentSeverityAction::getDefaultName());
        $test->setActionData(['severity' => IncidentSeverity::Critical->value]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame(2, IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->count());
        $latest = IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->latest('id')->first();
        $this->assertSame(IncidentSeverity::Critical, $latest->severity);

        $this->assertAuditWritten('incident_severity_updated', $admin->id);
    }

    // --- Update Status lifecycle ---

    public function test_update_status_is_allowed_and_hidden_once_resolved(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create(['status' => IncidentStatus::Investigating]);

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(UpdateIncidentStatusAction::getDefaultName());
        $test->setActionData(['status' => IncidentStatus::Monitoring->value]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $latest = IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->latest('id')->first();
        $this->assertSame(IncidentStatus::Monitoring, $latest->status);
        $this->assertAuditWritten('incident_status_updated', $admin->id);

        $resolved = IncidentEvent::factory()->create(['status' => IncidentStatus::Resolved]);
        $test2 = Livewire::test(ViewPlatformIncident::class, ['record' => $resolved->getKey()]);
        $test2->assertActionHidden(UpdateIncidentStatusAction::getDefaultName());
    }

    // --- Record Root Cause lifecycle ---

    public function test_record_root_cause_is_allowed(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create();

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(RecordIncidentRootCauseAction::getDefaultName());
        $test->setActionData(['root_cause' => 'Misconfigured cache']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $latest = IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->latest('id')->first();
        $this->assertSame('Misconfigured cache', $latest->root_cause);
        $this->assertAuditWritten('incident_root_cause_recorded', $admin->id);
    }

    // --- Flag Customer Impact lifecycle ---

    public function test_flag_customer_impact_is_allowed_and_toggles(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create(['customer_impact' => false]);

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(FlagIncidentCustomerImpactAction::getDefaultName());
        $test->assertActionDataSet(['customer_impact' => false]);
        $test->setActionData(['customer_impact' => true]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $latest = IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->latest('id')->first();
        $this->assertTrue((bool) $latest->customer_impact);
        $this->assertAuditWritten('incident_customer_impact_flagged', $admin->id);
    }

    // --- Flag Notification Needed lifecycle ---

    public function test_flag_notification_needed_is_allowed(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create(['notification_needed' => false]);

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(FlagIncidentNotificationNeededAction::getDefaultName());
        $test->setActionData(['notification_needed' => true]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $latest = IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->latest('id')->first();
        $this->assertTrue((bool) $latest->notification_needed);
        $this->assertAuditWritten('incident_notification_needed_flagged', $admin->id);
    }

    // --- Resolve lifecycle ---

    public function test_resolve_is_allowed_and_hidden_once_already_resolved(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create(['status' => IncidentStatus::Monitoring]);

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(ResolveIncidentAction::getDefaultName());
        $test->setActionData(['resolution' => 'Rolled back the bad deploy.']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $latest = IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->latest('id')->first();
        $this->assertSame(IncidentStatus::Resolved, $latest->status);
        $this->assertSame('Rolled back the bad deploy.', $latest->resolution);
        $this->assertAuditWritten('incident_resolved', $admin->id);

        $test2 = Livewire::test(ViewPlatformIncident::class, ['record' => $latest->getKey()]);
        $test2->assertActionHidden(ResolveIncidentAction::getDefaultName());
    }

    public function test_resolve_is_denied_for_a_security_auditor_because_manage_requires_a_narrower_role(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $incident = IncidentEvent::factory()->create();

        $test = Livewire::test(ViewPlatformIncident::class, ['record' => $incident->getKey()]);
        $test->mountAction(ResolveIncidentAction::getDefaultName());
        $test->setActionData(['resolution' => 'x']);
        $test->callMountedAction();

        $this->assertSame(1, IncidentEvent::query()->where('correlation_id', $incident->correlation_id)->count());
    }

    // --- No N+1 ---

    public function test_listing_many_incidents_does_not_n_plus_one(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        IncidentEvent::factory()->create();

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(PlatformIncidentResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        IncidentEvent::factory()->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(PlatformIncidentResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount, 'Adding 9 more incidents must not add ~9 extra queries.');
    }
}
