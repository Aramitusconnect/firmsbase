<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ActivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\DeactivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\PublishDirectoryFirmAction;
use App\Filament\Actions\Platform\RemoveDirectoryFirmAction;
use App\Filament\Actions\Platform\RevokeFirmVerificationAction;
use App\Filament\Actions\Platform\SuspendDirectoryFirmAction;
use App\Filament\Actions\Platform\VerifyFirmAuthorityAction;
use App\Filament\Resources\DirectoryFirmResource;
use App\Filament\Resources\DirectoryFirmResource\Pages\ListDirectoryFirms;
use App\Filament\Resources\DirectoryFirmResource\Pages\ViewDirectoryFirm;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationSource;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
use App\Marketplace\Services\MarketplaceVerificationService;
use App\Models\Firm;
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
 * DirectoryFirmResourceTest — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 11. Navigation/route-level access control, and the full
 * lifecycle (including step-up gating where applicable) of every
 * moderation/membership/verification Action wired onto this Resource.
 */
final class DirectoryFirmResourceTest extends TestCase
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
        $this->assertFalse(DirectoryFirmResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(DirectoryFirmResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_sales_rep(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(DirectoryFirmResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_super_admin_and_a_platform_admin(): void
    {
        foreach ([PlatformRoleCode::SuperAdmin, PlatformRoleCode::PlatformAdmin] as $role) {
            $admin = $this->adminWithRole($role);
            $this->actingAs($admin, 'platform_admin');

            $this->assertTrue(DirectoryFirmResource::canAccess());
        }
    }

    public function test_guest_is_redirected_from_the_list(): void
    {
        $this->get(DirectoryFirmResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden_at_the_route_level(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(DirectoryFirmResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $firm = DirectoryFirm::factory()->create(['display_name' => 'Acme Legal']);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(DirectoryFirmResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Acme Legal');

        $viewResponse = $this->get(DirectoryFirmResource::getUrl('view', ['record' => $firm]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Acme Legal');
    }

    // --- Publish ---

    public function test_publish_action_is_visible_only_when_not_already_published_and_transitions_state(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->draft()->create();

        $test = Livewire::test(ViewDirectoryFirm::class, ['record' => $firm->uuid]);
        $test->assertActionVisible(PublishDirectoryFirmAction::getDefaultName());
        $test->mountAction(PublishDirectoryFirmAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $firm->refresh();
        $this->assertSame(DirectoryPublicationState::Published, $firm->publication_state);
        $this->assertAuditWritten('marketplace_listing_published', $actor->id);

        $test2 = Livewire::test(ViewDirectoryFirm::class, ['record' => $firm->uuid]);
        $test2->assertActionHidden(PublishDirectoryFirmAction::getDefaultName());
    }

    public function test_publish_action_is_denied_for_a_sales_rep(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $firm = DirectoryFirm::factory()->draft()->create();

        $this->actingAs($actor, 'platform_admin')
            ->get(DirectoryFirmResource::getUrl('view', ['record' => $firm]))
            ->assertForbidden();
    }

    // --- Suspend ---

    public function test_suspend_action_is_visible_only_when_published_and_calls_the_real_service(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Published]);

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->assertTableActionVisible(SuspendDirectoryFirmAction::getDefaultName(), $firm);
        $test->mountTableAction(SuspendDirectoryFirmAction::getDefaultName(), $firm);
        $test->setActionData(['reason' => 'Reported for stale contact information.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $firm->refresh();
        $this->assertSame(DirectoryPublicationState::Suspended, $firm->publication_state);
        $this->assertAuditWritten('marketplace_listing_suspended', $actor->id);
    }

    public function test_suspend_action_is_hidden_for_a_draft_listing(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->draft()->create();

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->assertTableActionHidden(SuspendDirectoryFirmAction::getDefaultName(), $firm);
    }

    // --- Remove (step-up gated) ---

    public function test_remove_action_requires_the_password_field_without_a_recent_step_up_verification(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->create();

        $action = RemoveDirectoryFirmAction::make();
        $components = $this->resolveSchemaComponents($action);

        $this->assertCount(2, $components, 'The reason field plus the step-up password field must both be present.');
    }

    public function test_remove_action_omits_the_password_field_with_a_recent_step_up_verification(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $firm = DirectoryFirm::factory()->create();

        $action = RemoveDirectoryFirmAction::make();
        $components = $this->resolveSchemaComponents($action);

        $this->assertCount(1, $components, 'Only the reason field should remain once step-up is already verified.');
    }

    public function test_remove_action_transitions_the_listing_and_writes_an_audit_event_once_step_up_verified(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $firm = DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Published]);

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->mountTableAction(RemoveDirectoryFirmAction::getDefaultName(), $firm);
        $test->setActionData(['reason' => 'Confirmed fraudulent listing.']);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $firm->refresh();
        $this->assertSame(DirectoryPublicationState::Removed, $firm->publication_state);
        $this->assertAuditWritten('marketplace_listing_removed', $actor->id);
    }

    public function test_remove_action_is_hidden_once_already_removed(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Removed]);

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->assertTableActionHidden(RemoveDirectoryFirmAction::getDefaultName(), $firm);
    }

    // --- Membership (step-up gated) ---

    public function test_activate_membership_action_is_visible_only_for_a_claimed_non_member_listing(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $unclaimed = DirectoryFirm::factory()->unclaimed()->create();
        $claimed = DirectoryFirm::factory()->claimed()->create();

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->assertTableActionHidden(ActivateMarketplaceMembershipAction::getDefaultName(), $unclaimed);
        $test->assertTableActionVisible(ActivateMarketplaceMembershipAction::getDefaultName(), $claimed);
    }

    public function test_activate_membership_action_requires_step_up_and_calls_the_real_service(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->claimed()->create();

        $action = ActivateMarketplaceMembershipAction::make();
        $this->assertCount(1, $this->resolveSchemaComponents($action), 'Confirm-only step-up action must expose exactly the password field when unverified.');

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->mountTableAction(ActivateMarketplaceMembershipAction::getDefaultName(), $firm);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $firm->refresh();
        $this->assertTrue($firm->is_marketplace_member);
        $this->assertNotNull($firm->membership_activated_at);
        $this->assertAuditWritten('marketplace_membership_activated', $actor->id);
    }

    public function test_deactivate_membership_action_is_visible_only_for_a_member_and_calls_the_real_service(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($actor, 'platform_admin');

        $notMember = DirectoryFirm::factory()->claimed()->create();
        $member = DirectoryFirm::factory()->member()->create();

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->assertTableActionHidden(DeactivateMarketplaceMembershipAction::getDefaultName(), $notMember);
        $test->assertTableActionVisible(DeactivateMarketplaceMembershipAction::getDefaultName(), $member);

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test2 = Livewire::test(ListDirectoryFirms::class);
        $test2->mountTableAction(DeactivateMarketplaceMembershipAction::getDefaultName(), $member);
        $test2->setActionData(['reason' => 'Membership lapsed.']);
        $test2->callMountedTableAction();
        $test2->assertHasNoTableActionErrors();

        $member->refresh();
        $this->assertFalse($member->is_marketplace_member);
        $this->assertAuditWritten('marketplace_membership_deactivated', $actor->id);
    }

    // --- Verify / Revoke Firm Authority (step-up gated, on the View page) ---

    public function test_verify_firm_authority_action_is_hidden_once_verified_and_grants_the_badge(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->create();

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test = Livewire::test(ViewDirectoryFirm::class, ['record' => $firm->uuid]);
        $test->assertActionVisible(VerifyFirmAuthorityAction::getDefaultName());
        $test->mountAction(VerifyFirmAuthorityAction::getDefaultName());
        $test->setActionData(['source' => VerificationSource::AdminDocumentReview->value, 'notes' => 'Bar record confirmed.']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertTrue(app(MarketplaceVerificationService::class)->isVerified($firm->fresh(), VerificationDimension::FirmAuthority));
        $this->assertAuditWritten('marketplace_verification_verified', $actor->id);

        $test2 = Livewire::test(ViewDirectoryFirm::class, ['record' => $firm->uuid]);
        $test2->assertActionHidden(VerifyFirmAuthorityAction::getDefaultName());
        $test2->assertActionVisible(RevokeFirmVerificationAction::getDefaultName());
    }

    public function test_revoke_firm_verification_action_requires_step_up_and_reason_then_revokes(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $firm = DirectoryFirm::factory()->create();
        DirectoryVerification::factory()->forVerifiable($firm, VerificationDimension::FirmAuthority)->verified()->create();

        $action = RevokeFirmVerificationAction::make();
        $this->assertCount(2, $this->resolveSchemaComponents($action));

        app(StepUpAuthenticationService::class)->markVerified('platform_admin');

        $test = Livewire::test(ViewDirectoryFirm::class, ['record' => $firm->uuid]);
        $test->mountAction(RevokeFirmVerificationAction::getDefaultName());
        $test->setActionData(['reason' => 'Underlying license lapsed.']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertFalse(app(MarketplaceVerificationService::class)->isVerified($firm->fresh(), VerificationDimension::FirmAuthority));
        $this->assertAuditWritten('marketplace_verification_revoked', $actor->id);
    }

    // --- Linked-tenant-firm audit routing branch ---

    public function test_moderation_audit_routes_through_the_linked_tenant_firm_when_one_exists(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $tenantFirm = Firm::factory()->create();
        $firm = DirectoryFirm::factory()->create(['firm_id' => $tenantFirm->id, 'publication_state' => DirectoryPublicationState::Published]);

        $test = Livewire::test(ListDirectoryFirms::class);
        $test->mountTableAction(SuspendDirectoryFirmAction::getDefaultName(), $firm);
        $test->callMountedTableAction();
        $test->assertHasNoTableActionErrors();

        $row = app(TenantContextService::class)->runWithFirmContext(
            $tenantFirm,
            fn () => DB::table('security_events')
                ->where('event_type', 'marketplace_listing_suspended')
                ->where('firm_id', $tenantFirm->id)
                ->first()
        );
        $this->assertNotNull($row, 'When a tenant Firm is linked, the audit row must carry that real firm_id, not null.');
    }
}
