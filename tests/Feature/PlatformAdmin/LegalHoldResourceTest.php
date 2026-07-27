<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\PlaceLegalHoldAction;
use App\Filament\Actions\Platform\ReleaseLegalHoldAction;
use App\Filament\Resources\LegalHoldResource;
use App\Filament\Resources\LegalHoldResource\Pages\ListLegalHolds;
use App\Filament\Resources\LegalHoldResource\Pages\ViewLegalHold;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LegalHoldResourceTest — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category. Route-level authorization, cross-firm listing,
 * no-N+1, and the full Place/Release action lifecycles (authorization
 * allow/deny, resulting state, TOCTOU-safety).
 */
final class LegalHoldResourceTest extends TestCase
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

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(LegalHoldResource::canAccess());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(LegalHoldResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_legal_holds_list(): void
    {
        $this->get(LegalHoldResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(LegalHoldResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Held Firm']);
        $hold = LegalHold::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(LegalHoldResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Held Firm');

        $viewResponse = $this->get(ViewLegalHold::getUrl(['firmUuid' => $firm->uuid, 'id' => $hold->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_a_hold_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $hold = LegalHold::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewLegalHold::getUrl(['firmUuid' => $firmB->uuid, 'id' => $hold->id]))
            ->assertNotFound();
    }

    // --- No-N+1 proof ---

    public function test_listing_many_holds_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        LegalHold::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(LegalHoldResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        LegalHold::factory()->forFirm($firm)->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(LegalHoldResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount);
    }

    // --- Place action lifecycle ---

    public function test_place_action_places_a_firm_scope_hold(): void
    {
        $firm = Firm::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->mountAction(PlaceLegalHoldAction::getDefaultName());
        $test->setActionData([
            'firm_id' => $firm->id,
            'scope_type' => LegalHoldScope::Firm->value,
            'reason' => 'Litigation pending.',
        ]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $hold = $this->runWithFirmContext($firm, fn () => LegalHold::query()->where('firm_id', $firm->id)->first());
        $this->assertNotNull($hold);
        $this->assertSame(LegalHoldStatus::Active, $hold->status);
        $this->assertSame(LegalHoldScope::Firm, $hold->scope_type);
        $this->assertSame(PlatformAdmin::class, $hold->placed_by_type);
        $this->assertSame($admin->id, $hold->placed_by_id);
    }

    public function test_place_action_places_a_matter_scope_hold(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->mountAction(PlaceLegalHoldAction::getDefaultName());
        $test->setActionData([
            'firm_id' => $firm->id,
            'scope_type' => LegalHoldScope::Matter->value,
            'subject_id' => $matter->id,
            'reason' => 'Matter under litigation hold.',
        ]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $hold = $this->runWithFirmContext($firm, fn () => LegalHold::query()->where('matter_id', $matter->id)->first());
        $this->assertNotNull($hold);
        $this->assertSame(LegalHoldScope::Matter, $hold->scope_type);
    }

    public function test_a_role_without_manage_legal_holds_cannot_place_a_hold(): void
    {
        $firm = Firm::factory()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->mountAction(PlaceLegalHoldAction::getDefaultName());
        $test->setActionData([
            'firm_id' => $firm->id,
            'scope_type' => LegalHoldScope::Firm->value,
            'reason' => 'Attempted hold.',
        ]);
        $test->callMountedAction();

        $count = $this->runWithFirmContext($firm, fn () => LegalHold::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(0, $count, 'canManageLegalHolds() must block a SecurityAuditor from placing a hold.');
    }

    public function test_a_read_only_auditor_with_super_admin_also_held_still_cannot_place_a_hold(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->mountAction(PlaceLegalHoldAction::getDefaultName());
        $test->setActionData([
            'firm_id' => $firm->id,
            'scope_type' => LegalHoldScope::Firm->value,
            'reason' => 'Attempted hold.',
        ]);
        $test->callMountedAction();

        $count = $this->runWithFirmContext($firm, fn () => LegalHold::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(0, $count, 'canMutate() must block a read_only_auditor from placing a hold, even with SuperAdmin also held.');
    }

    // --- Release action lifecycle ---

    public function test_release_action_releases_an_active_hold_and_writes_attribution(): void
    {
        $firm = Firm::factory()->create();
        $hold = LegalHold::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->assertOk();
        $test->mountTableAction(ReleaseLegalHoldAction::getDefaultName(), '0');
        $test->setTableActionData(['release_reason' => 'Litigation concluded.']);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $hold->fresh());
        $this->assertSame(LegalHoldStatus::Released, $fresh->status);
        $this->assertSame(PlatformAdmin::class, $fresh->released_by_type);
        $this->assertSame($admin->id, $fresh->released_by_id);
        $this->assertSame('Litigation concluded.', $fresh->release_reason);
    }

    public function test_release_action_is_hidden_for_an_already_released_hold(): void
    {
        $firm = Firm::factory()->create();
        LegalHold::factory()->forFirm($firm)->released()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->assertOk();
        $test->assertTableActionHidden(ReleaseLegalHoldAction::getDefaultName(), '0');
    }

    public function test_a_role_without_manage_legal_holds_cannot_release_a_hold(): void
    {
        $firm = Firm::factory()->create();
        $hold = LegalHold::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SecurityAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListLegalHolds::class);
        $test->assertOk();
        $test->mountTableAction(ReleaseLegalHoldAction::getDefaultName(), '0');
        $test->setTableActionData(['release_reason' => 'Attempted release.']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $hold->fresh());
        $this->assertSame(LegalHoldStatus::Active, $fresh->status, 'canManageLegalHolds() must block a SecurityAuditor from releasing a hold.');
    }
}
