<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\StatusPageEventStatus;
use App\Filament\Actions\Platform\PublishStatusPageEventAction;
use App\Filament\Actions\Platform\ResolveStatusPageEventPubliclyAction;
use App\Filament\Actions\Platform\UnpublishStatusPageEventAction;
use App\Filament\Actions\Platform\UpdateStatusPageEventAction;
use App\Filament\Pages\PlatformStatusPageEventsPage;
use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformStatusPageEventsPageTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Navigation, direct-route auth,
 * "current state" list semantics, filters/ordering/pagination, empty
 * state, and the full Publish/Update/ResolvePublicly/Unpublish
 * lifecycle.
 */
final class PlatformStatusPageEventsPageTest extends TestCase
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

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformStatusPageEventsPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformStatusPageEventsPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformStatusPageEventsPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformStatusPageEventsPage::getUrl())->assertOk();
    }

    public function test_list_shows_only_the_latest_row_per_correlation_id(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $correlationId = (string) Str::uuid();
        $original = StatusPageEvent::factory()->create(['correlation_id' => $correlationId, 'public_message' => 'first']);
        $updated = StatusPageEvent::factory()->create(['correlation_id' => $correlationId, 'public_message' => 'second']);

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->assertCanSeeTableRecords([$updated]);
        $test->assertCanNotSeeTableRecords([$original]);
    }

    public function test_empty_state(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformStatusPageEventsPage::getUrl());
        $response->assertOk();
        $response->assertSee('No status page updates recorded yet');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $published = StatusPageEvent::factory()->create(['status' => StatusPageEventStatus::Published]);
        $draft = StatusPageEvent::factory()->create(['status' => StatusPageEventStatus::Draft]);

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->filterTable('status', StatusPageEventStatus::Draft->value);

        $test->assertCanSeeTableRecords([$draft]);
        $test->assertCanNotSeeTableRecords([$published]);
    }

    public function test_orders_deterministically_by_id_when_starts_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedTime = now();
        $events = StatusPageEvent::factory()->count(5)->create(['starts_at' => $sharedTime]);

        $first = Livewire::test(PlatformStatusPageEventsPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformStatusPageEventsPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($events->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        StatusPageEvent::factory()->count(30)->create();

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    public function test_publish_is_allowed_for_a_super_admin_and_writes_audit_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->mountAction(PublishStatusPageEventAction::getDefaultName());
        $test->setActionData([
            'event_type' => 'investigating',
            'component_affected' => 'client_portal',
            'public_message' => 'We are investigating.',
            'starts_at' => now()->toDateTimeString(),
        ]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame(1, StatusPageEvent::query()->count());
        $this->assertAuditWritten('status_page_event_published', $admin->id);
    }

    public function test_publish_is_denied_for_a_read_only_auditor(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->mountAction(PublishStatusPageEventAction::getDefaultName());
        $test->setActionData([
            'event_type' => 'investigating',
            'component_affected' => 'x',
            'public_message' => 'x',
            'starts_at' => now()->toDateTimeString(),
        ]);
        $test->callMountedAction();

        $this->assertSame(0, StatusPageEvent::query()->count());
    }

    public function test_update_appends_a_new_row(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $event = StatusPageEvent::factory()->create();

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->callTableAction(UpdateStatusPageEventAction::getDefaultName(), $event, data: [
            'event_type' => 'identified',
            'public_message' => 'Root cause identified.',
        ]);
        $test->assertHasNoTableActionErrors();

        $this->assertSame(2, StatusPageEvent::query()->where('correlation_id', $event->correlation_id)->count());
        $this->assertAuditWritten('status_page_event_updated', $admin->id);
    }

    public function test_resolve_publicly_sets_resolved_at_and_is_hidden_once_unpublished(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $event = StatusPageEvent::factory()->create(['status' => StatusPageEventStatus::Published]);

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->callTableAction(ResolveStatusPageEventPubliclyAction::getDefaultName(), $event, data: [
            'public_message' => 'Resolved.',
        ]);
        $test->assertHasNoTableActionErrors();

        $latest = StatusPageEvent::query()->where('correlation_id', $event->correlation_id)->latest('id')->first();
        $this->assertNotNull($latest->resolved_at);
        $this->assertAuditWritten('status_page_event_resolved_publicly', $admin->id);
    }

    public function test_unpublish_sets_status_unpublished_and_is_hidden_once_already_unpublished(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $event = StatusPageEvent::factory()->create(['status' => StatusPageEventStatus::Published]);

        $test = Livewire::test(PlatformStatusPageEventsPage::class);
        $test->callTableAction(UnpublishStatusPageEventAction::getDefaultName(), $event);
        $test->assertHasNoTableActionErrors();

        $latest = StatusPageEvent::query()->where('correlation_id', $event->correlation_id)->latest('id')->first();
        $this->assertSame(StatusPageEventStatus::Unpublished, $latest->status);
        $this->assertAuditWritten('status_page_event_unpublished', $admin->id);

        $test2 = Livewire::test(PlatformStatusPageEventsPage::class);
        $test2->assertTableActionHidden(UnpublishStatusPageEventAction::getDefaultName(), $latest);
    }
}
