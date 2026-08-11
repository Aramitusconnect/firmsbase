<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ApproveDirectoryClaimAction;
use App\Filament\Actions\Platform\RejectDirectoryClaimAction;
use App\Filament\Actions\Platform\RevokeDirectoryClaimAction;
use App\Filament\Resources\DirectoryClaimResource;
use App\Filament\Resources\DirectoryClaimResource\Pages\ListDirectoryClaims;
use App\Filament\Resources\DirectoryClaimResource\Pages\ViewDirectoryClaim;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\Security\StepUpAuthenticationService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * DirectoryClaimResourceTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Navigation/access control, plus the full
 * Approve/Reject/Revoke lifecycle — Approve and Revoke are step-up
 * gated per the mission's own named high-risk list, Reject is not.
 */
final class DirectoryClaimResourceTest extends TestCase
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

    private function resolveSchemaComponents(Action $action): array
    {
        $property = new ReflectionProperty(Action::class, 'schema');
        $property->setAccessible(true);
        $schema = $property->getValue($action);

        return $schema instanceof \Closure ? $schema() : $schema;
    }

    /**
     * Every DirectoryClaim carries a real, non-nullable claimant
     * firm_id, so MarketplaceClaimService's audit events always route
     * through that real tenant Firm's context (never the null-firm
     * fallback) — reading the row back requires the same context.
     */
    private function assertAuditWritten(DirectoryClaim $claim, string $eventType, int $actorId): void
    {
        $tenantFirm = $claim->firm()->firstOrFail();

        $row = app(TenantContextService::class)->runWithFirmContext(
            $tenantFirm,
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
        $this->assertFalse(DirectoryClaimResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(DirectoryClaimResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_list(): void
    {
        $this->get(DirectoryClaimResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $claim = DirectoryClaim::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->get(DirectoryClaimResource::getUrl())->assertOk();
        $this->get(DirectoryClaimResource::getUrl('view', ['record' => $claim]))->assertOk();
    }

    // --- Approve (step-up gated) ---

    public function test_approve_action_is_visible_only_while_active_and_requires_step_up(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $claim = DirectoryClaim::factory()->create();
        $decided = DirectoryClaim::factory()->approved()->create();

        $test = Livewire::test(ListDirectoryClaims::class);
        $test->assertTableActionVisible(ApproveDirectoryClaimAction::getDefaultName(), $claim);
        $test->assertTableActionHidden(ApproveDirectoryClaimAction::getDefaultName(), $decided);

        $action = ApproveDirectoryClaimAction::make();
        $this->assertCount(1, $this->resolveSchemaComponents($action), 'Confirm-only step-up action must expose the password field when unverified.');
    }

    public function test_approve_action_links_the_listing_and_writes_an_audit_event_once_step_up_verified(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $claim = DirectoryClaim::factory()->create();

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test = Livewire::test(ListDirectoryClaims::class);
        $test->mountTableAction(ApproveDirectoryClaimAction::getDefaultName(), $claim);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $claim->refresh();
        $this->assertSame(ClaimState::Approved, $claim->state);

        $directoryFirm = $claim->directoryFirm()->first();
        $this->assertTrue($directoryFirm->is_claimed);
        $this->assertSame($claim->firm_id, $directoryFirm->firm_id);
        $this->assertAuditWritten($claim, 'marketplace_claim_approved', $actor->id);
    }

    public function test_approve_action_shows_an_error_notification_when_the_listing_is_already_claimed(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $claim = DirectoryClaim::factory()->create();
        $claim->directoryFirm->update(['is_claimed' => true, 'firm_id' => $claim->firm_id, 'claimed_at' => now()]);

        $test = Livewire::test(ListDirectoryClaims::class);
        $test->mountTableAction(ApproveDirectoryClaimAction::getDefaultName(), $claim);
        $test->callMountedTableAction();
        $test->assertNotified();

        $claim->refresh();
        $this->assertSame(ClaimState::Pending, $claim->state, 'A claim on an already-claimed listing must not be silently approved.');
    }

    // --- Reject (not step-up gated) ---

    public function test_reject_action_requires_a_reason_and_is_not_step_up_gated(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($actor, 'platform_admin');

        $claim = DirectoryClaim::factory()->create();

        $action = RejectDirectoryClaimAction::make();
        $this->assertCount(1, $this->resolveSchemaComponents($action), 'Reject must never gain a step-up password field.');

        $test = Livewire::test(ListDirectoryClaims::class);
        $test->mountTableAction(RejectDirectoryClaimAction::getDefaultName(), $claim);
        $test->setActionData(['reason' => 'No verifiable evidence of authority.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $claim->refresh();
        $this->assertSame(ClaimState::Rejected, $claim->state);
        $this->assertSame('No verifiable evidence of authority.', $claim->rejection_reason);
        $this->assertAuditWritten($claim, 'marketplace_claim_rejected', $actor->id);
    }

    // --- Revoke (step-up gated) ---

    public function test_revoke_action_is_visible_only_for_an_approved_claim_and_unlinks_the_listing(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $claim = DirectoryClaim::factory()->approved()->create();
        $claim->directoryFirm->update(['is_claimed' => true, 'firm_id' => $claim->firm_id, 'claimed_at' => now()]);

        $pending = DirectoryClaim::factory()->create();

        $test = Livewire::test(ListDirectoryClaims::class);
        $test->assertTableActionVisible(RevokeDirectoryClaimAction::getDefaultName(), $claim);
        $test->assertTableActionHidden(RevokeDirectoryClaimAction::getDefaultName(), $pending);

        $action = RevokeDirectoryClaimAction::make();
        $this->assertCount(2, $this->resolveSchemaComponents($action));

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test2 = Livewire::test(ViewDirectoryClaim::class, ['record' => $claim->uuid]);
        $test2->mountAction(RevokeDirectoryClaimAction::getDefaultName());
        $test2->setActionData(['reason' => 'Claimant no longer with the firm.']);
        $test2->callMountedAction();
        $test2->assertHasNoActionErrors();

        $claim->refresh();
        $this->assertSame(ClaimState::Revoked, $claim->state);
        $this->assertFalse($claim->directoryFirm->fresh()->is_claimed);
        $this->assertAuditWritten($claim, 'marketplace_claim_revoked', $actor->id);
    }
}
