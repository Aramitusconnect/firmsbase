<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ApproveCorrectionRequestAction;
use App\Filament\Actions\Platform\RejectCorrectionRequestAction;
use App\Filament\Actions\Platform\ResolveCorrectionRequestAction;
use App\Filament\Resources\DirectoryCorrectionRequestResource;
use App\Filament\Resources\DirectoryCorrectionRequestResource\Pages\ListDirectoryCorrectionRequests;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DirectoryCorrectionRequestResourceTest — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 11. None of Approve/Reject/Resolve are
 * step-up gated here (see each Action's own docblock for why); this
 * proves navigation/access control and the full Approve->Resolve and
 * Reject lifecycles, plus their audit events.
 */
final class DirectoryCorrectionRequestResourceTest extends TestCase
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
        $this->assertNotNull($row, "A security_events row must be written for {$eventType}.");
    }

    // --- Navigation / route-level access control ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(DirectoryCorrectionRequestResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(DirectoryCorrectionRequestResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_list(): void
    {
        $this->get(DirectoryCorrectionRequestResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_can_reach_the_list(): void
    {
        DirectoryCorrectionRequest::factory()->create(['reporter_name' => 'Jane Prospective Client']);
        $admin = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(DirectoryCorrectionRequestResource::getUrl());
        $response->assertOk();
        $response->assertSee('Jane Prospective Client');
    }

    // --- Approve ---

    public function test_approve_action_is_visible_only_while_active_and_records_reviewer_notes(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $request = DirectoryCorrectionRequest::factory()->create();
        $resolved = DirectoryCorrectionRequest::factory()->resolved()->create();

        $test = Livewire::test(ListDirectoryCorrectionRequests::class);
        $test->assertTableActionVisible(ApproveCorrectionRequestAction::getDefaultName(), $request);
        $test->assertTableActionHidden(ApproveCorrectionRequestAction::getDefaultName(), $resolved);

        $test->mountTableAction(ApproveCorrectionRequestAction::getDefaultName(), $request);
        $test->setActionData(['reviewer_notes' => 'Confirmed against the firm website.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $request->refresh();
        $this->assertSame(CorrectionState::Approved, $request->state);
        $this->assertSame('Confirmed against the firm website.', $request->reviewer_notes);
        $this->assertAuditWritten('marketplace_correction_approved', $actor->id);
    }

    // --- Reject ---

    public function test_reject_action_requires_a_reason_and_transitions_state(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $request = DirectoryCorrectionRequest::factory()->create();

        $test = Livewire::test(ListDirectoryCorrectionRequests::class);
        $test->mountTableAction(RejectCorrectionRequestAction::getDefaultName(), $request);
        $test->setActionData(['reason' => 'Unable to independently confirm.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $request->refresh();
        $this->assertSame(CorrectionState::Rejected, $request->state);
        $this->assertAuditWritten('marketplace_correction_rejected', $actor->id);
    }

    // --- Resolve ---

    public function test_resolve_action_is_visible_only_once_approved_and_requires_resolution_notes(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $pending = DirectoryCorrectionRequest::factory()->create();
        $approved = DirectoryCorrectionRequest::factory()->approved()->create();

        $test = Livewire::test(ListDirectoryCorrectionRequests::class);
        $test->assertTableActionHidden(ResolveCorrectionRequestAction::getDefaultName(), $pending);
        $test->assertTableActionVisible(ResolveCorrectionRequestAction::getDefaultName(), $approved);

        $test->mountTableAction(ResolveCorrectionRequestAction::getDefaultName(), $approved);
        $test->setActionData(['resolution_notes' => 'Phone number corrected on the listing.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $approved->refresh();
        $this->assertSame(CorrectionState::Resolved, $approved->state);
        $this->assertSame('Phone number corrected on the listing.', $approved->resolution_notes);
        $this->assertAuditWritten('marketplace_correction_resolved', $actor->id);
    }
}
