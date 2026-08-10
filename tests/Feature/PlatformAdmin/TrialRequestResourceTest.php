<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\TrialRequestStatus;
use App\Filament\Actions\Platform\ActivateTrialRequestAction;
use App\Filament\Actions\Platform\ConvertTrialRequestAction;
use App\Filament\Actions\Platform\ExpireTrialRequestAction;
use App\Filament\Actions\Platform\ProvisionTrialRequestAction;
use App\Filament\Resources\TrialRequestResource;
use App\Filament\Resources\TrialRequestResource\Pages\ListTrialRequests;
use App\Filament\Resources\TrialRequestResource\Pages\ViewTrialRequest;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\PlatformLead;
use App\Models\TrialRequest;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TrialRequestResourceTest — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Navigation
 * visibility, route-level authorization, filters, deterministic
 * ordering, bounded pagination, no-N+1, and the
 * Provision/Activate/Expire/Convert actions' full lifecycle.
 */
final class TrialRequestResourceTest extends TestCase
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

    private function opportunityFor(string $companyName): Opportunity
    {
        $lead = PlatformLead::factory()->create(['company_name' => $companyName]);

        return Opportunity::factory()->forLead($lead)->create();
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(TrialRequestResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(TrialRequestResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(TrialRequestResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_trial_requests_list(): void
    {
        $this->get(TrialRequestResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(TrialRequestResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $opportunity = $this->opportunityFor('Acme Law Firm');
        $trial = TrialRequest::factory()->forOpportunity($opportunity)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(TrialRequestResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Acme Law Firm');

        $viewResponse = $this->actingAs($admin, 'platform_admin')
            ->get(TrialRequestResource::getUrl('view', ['record' => $trial]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Acme Law Firm');
    }

    // --- Empty state ---

    public function test_the_list_page_shows_an_empty_state_with_no_trial_requests(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(TrialRequestResource::getUrl());
        $response->assertOk();
        $response->assertSee('No trial requests found');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $requested = TrialRequest::factory()->create(['status' => TrialRequestStatus::Requested]);
        $expired = TrialRequest::factory()->create(['status' => TrialRequestStatus::Expired]);

        $test = Livewire::test(ListTrialRequests::class);
        $test->filterTable('status', TrialRequestStatus::Requested->value);

        $test->assertCanSeeTableRecords([$requested]);
        $test->assertCanNotSeeTableRecords([$expired]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_requested_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $tied = now();
        $rows = TrialRequest::factory()->count(5)->create(['requested_at' => $tied]);

        $first = Livewire::test(ListTrialRequests::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListTrialRequests::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied requested_at rows must order identically across repeated calls.');
        $this->assertSame($rows->sortByDesc('id')->pluck('id')->values()->all(), $first, 'defaultSort is desc, so the id tie-breaker should also resolve descending relative to insertion.');
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        TrialRequest::factory()->count(30)->create();

        $test = Livewire::test(ListTrialRequests::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- No N+1 ---

    public function test_the_list_page_does_not_n_plus_one_on_opportunity_or_organization(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $organization = Organization::factory()->create();
        foreach (range(1, 8) as $i) {
            $opportunity = $this->opportunityFor("Firm {$i}");
            TrialRequest::factory()->forOpportunity($opportunity)->create(['organization_id' => $organization->id]);
        }

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });

        Livewire::test(ListTrialRequests::class)->assertSuccessful();

        $opportunityQueries = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, 'opportunities'))->count();
        $leadQueries = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, 'platform_leads'))->count();

        $this->assertLessThanOrEqual(1, $opportunityQueries, 'Expected at most one batched opportunities query, never one per row.');
        $this->assertLessThanOrEqual(1, $leadQueries, 'Expected at most one batched platform_leads query, never one per row.');
    }

    // --- Provision action lifecycle ---

    public function test_provision_action_attaches_the_chosen_organization_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Requested]);
        $organization = Organization::factory()->create(['name' => 'Target Org']);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->mountAction(ProvisionTrialRequestAction::getDefaultName());
        $test->setActionData(['organization_id' => $organization->id]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $trial->refresh();
        $this->assertSame(TrialRequestStatus::Provisioned, $trial->status);
        $this->assertSame($organization->id, $trial->organization_id);
        $this->assertNotNull($trial->provisioned_at);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'trial_provisioned')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_provision_action_is_not_visible_once_already_provisioned(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Provisioned]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->assertActionHidden(ProvisionTrialRequestAction::getDefaultName());
    }

    // --- Activate action lifecycle ---

    public function test_activate_action_moves_a_provisioned_trial_to_active(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Provisioned]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->mountAction(ActivateTrialRequestAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $trial->refresh();
        $this->assertSame(TrialRequestStatus::Active, $trial->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'trial_activated')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    // --- Convert action lifecycle ---

    public function test_convert_action_moves_an_active_trial_to_converted(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $organization = Organization::factory()->create();
        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Active, 'organization_id' => $organization->id]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->mountAction(ConvertTrialRequestAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $trial->refresh();
        $this->assertSame(TrialRequestStatus::Converted, $trial->status);
        $this->assertNotNull($trial->converted_at);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'trial_converted')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_convert_action_is_not_visible_for_a_merely_requested_trial(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Requested]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->assertActionHidden(ConvertTrialRequestAction::getDefaultName());
    }

    // --- Expire action lifecycle ---

    public function test_expire_action_ends_an_active_trial_early(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Active]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->mountAction(ExpireTrialRequestAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $trial->refresh();
        $this->assertSame(TrialRequestStatus::Expired, $trial->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'trial_expired')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_expire_action_is_denied_for_a_billing_admin(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Active]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->mountAction(ExpireTrialRequestAction::getDefaultName());
        $test->callMountedAction();

        $trial->refresh();
        $this->assertSame(TrialRequestStatus::Active, $trial->status, 'A BillingAdmin must not be able to expire a trial.');
    }

    public function test_expire_action_is_not_visible_for_an_already_converted_trial(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $trial = TrialRequest::factory()->create(['status' => TrialRequestStatus::Converted, 'converted_at' => now()]);

        $test = Livewire::test(ViewTrialRequest::class, ['record' => $trial->uuid]);
        $test->assertActionHidden(ExpireTrialRequestAction::getDefaultName());
    }
}
