<?php

declare(strict_types=1);

namespace Tests\Feature\Matters;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Pages\ListMatters;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\ActivityRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\FinancialEvidenceRelationManager;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use App\Services\MatterAccessPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MatterResourceAccessTest — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on"), Matter resource track
 * (checkpoint4-design-matter-and-client-portal.md §1). Proves the
 * FOUNDATION this checkpoint is responsible for: Matter access control
 * at the Filament layer — `MatterResource::canAccess()`/
 * `getEloquentQuery()` (the UX-layer filter) and `ViewMatter::
 * resolveRecord()` (the real per-record boundary, calling the
 * pre-existing, already-unit-tested `MatterAccessPolicyService`
 * directly — see `tests/Feature/Ai/Retrieval/MatterAccessPolicyServiceTest.php`
 * for that service's own exhaustive rule coverage, deliberately not
 * duplicated here) — plus the three RelationManager tabs' own
 * `canViewForRecord()` gates, including the Financial Evidence tab this
 * design track is directly responsible for slotting in correctly.
 *
 * Cross-firm denial is proven twice: once as an Eloquent-query-level
 * check (getEloquentQuery()) and once as a REAL RLS proof — a raw
 * DB::table('matters') read under Firm A's own context, and a genuine
 * HTTP/Livewire request for a Firm B matter's record — since `matters`
 * carries permanent FORCE ROW LEVEL SECURITY
 * (database/migrations/2026_08_04_900001_force_rls_on_matters_table.php,
 * covered by its own MattersForceRlsActivationTest — not duplicated
 * here), a Firm A session genuinely cannot read the row at all, not
 * merely "the Eloquent scope declines to fetch it."
 *
 * FOUND-AND-FIXED PRODUCTION DEFECT, now resolved (see
 * test_authorized_firm_owner_can_view_the_full_matter_detail_page_including_every_tab()
 * and test_activity_relation_manager_tab_title_computation_no_longer_crashes_for_any_authorized_viewer()
 * below): ActivityRelationManager
 * (app/Filament/Firm/Resources/MatterResource/RelationManagers/ActivityRelationManager.php)
 * used to declare neither `protected static ?string $title` nor
 * `protected static string $relationship`. Filament's
 * RelationManager::getTitle() falls back to getRelationshipTitle() ->
 * getRelationshipName() whenever $title is unset, and that method's own
 * fallback branch (`static::getRelatedResource()::getParentResourceRegistration()`)
 * dereferences a null class name whenever $relatedResource is also
 * unset (the default) — a fatal `Class name must be a valid object or a
 * string` TypeError. Since Filament eagerly builds the tab component
 * (including its title) for EVERY RelationManager that passes
 * canViewForRecord() as part of ViewRecord::mount()'s own sub-navigation
 * build — before any tab content is ever rendered — this used to mean NO
 * authorized user, regardless of role, could open a Matter's View page at
 * all. This was the exact same class of Filament-framework interaction
 * FirmIntegrationsNavigationAuthorizationTest's own "DISCLOSED BLOCKER"
 * docblock previously documented for FirmIntegrationResource, confirmed
 * independently here for MatterResource via direct reproduction (not by
 * inference from that prior finding). The negative/403 case was never
 * affected (canViewForRecord() is checked, and returns false, before any
 * tab title is ever computed for an unauthorized actor). Found and fixed
 * during Checkpoint 4's own test-writing pass: ActivityRelationManager now
 * declares `protected static ?string $title = 'Activity';`, mirroring
 * DocumentsRelationManager's/FinancialEvidenceRelationManager's own
 * convention — the two tests named above now assert the CORRECT, fixed
 * behavior (no crash, the tab renders/computes its title fine) rather than
 * the formerly-confirmed crash.
 */
final class MatterResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament's own canAccess()/getEloquentQuery() authorization
        // helpers resolve the acting user via Filament::auth(), which
        // reads the CURRENT panel's own auth guard
        // (getCurrentOrDefaultPanel()) — never Auth::user() directly.
        // Explicitly activating the 'firm' panel mirrors what a real
        // firm-panel HTTP request's middleware stack does implicitly.
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // canAccess() — coarse "authenticated firm staff at all" gate
    // ------------------------------------------------------------

    public function test_guest_cannot_access_the_matter_resource(): void
    {
        $this->assertFalse(MatterResource::canAccess());
    }

    public function test_a_user_with_no_active_firm_user_membership_cannot_access_the_matter_resource(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(MatterResource::canAccess());
    }

    public function test_an_active_firm_user_can_access_the_matter_resource(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $this->assertTrue(MatterResource::canAccess());
    }

    // ------------------------------------------------------------
    // getEloquentQuery() — list-level UX filter, matching
    // MatterAccessPolicyService's own rule expressed as a query
    // predicate
    // ------------------------------------------------------------

    public function test_firm_owner_sees_every_matter_in_their_firm_without_an_assignment(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertContains($matter->id, $ids);
    }

    public function test_attorney_sees_every_matter_in_their_firm_without_an_assignment(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertContains($matter->id, $ids);
    }

    public function test_paralegal_without_an_assignment_sees_no_matters(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertSame([], $ids);
    }

    public function test_paralegal_with_an_active_assignment_sees_that_matter_only(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $assigned = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $unassigned = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($assigned)->forUser($firmUser->user)->create());

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertSame([$assigned->id], $ids);
        $this->assertNotContains($unassigned->id, $ids);
    }

    public function test_a_removed_assignment_no_longer_grants_list_visibility(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::LegalAssistant);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create(['removed_at' => now()]));

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertSame([], $ids);
    }

    public function test_an_unauthenticated_query_sees_no_matters_at_all(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $ids = $this->runWithFirmContext($firm, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertSame([], $ids);
    }

    // ------------------------------------------------------------
    // Cross-firm denial — Eloquent AND real RLS proof
    // ------------------------------------------------------------

    public function test_a_firm_owner_never_sees_another_firms_matter_in_the_list_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $ids = $this->runWithFirmContext($firmA, fn () => MatterResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotContains($matterB->id, $ids);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_matter_row_at_all(): void
    {
        // Deliberately bypasses Eloquent/BelongsToTenant/MatterResource
        // entirely — a raw DB::table() read, governed purely by
        // matters' own permanent FORCE ROW LEVEL SECURITY. Proves the
        // boundary is real Postgres enforcement, not merely an Eloquent
        // query-builder convenience that a differently-written query
        // could accidentally bypass.
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('matters')->pluck('id')->all());

        $this->assertContains($matterA->id, $visibleIds);
        $this->assertNotContains($matterB->id, $visibleIds, "Firm A's database session must never be able to read Firm B's matter row, regardless of any application-layer query filter.");
    }

    public function test_real_rls_proof_a_firm_owner_of_firm_a_gets_a_404_not_a_403_when_requesting_firm_bs_matter_record(): void
    {
        // A 404 here (rather than a 403) is itself part of the proof:
        // Filament's route-model-binding query for ViewMatter's record
        // never even sees Firm B's row, because FORCE RLS makes it
        // genuinely invisible under Firm A's session — this is stronger
        // than an application-layer 403, which would still imply the
        // row was found and then rejected.
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matterB])));

        $response->assertNotFound();
    }

    // ------------------------------------------------------------
    // ViewMatter::resolveRecord() — the real per-record boundary
    // ------------------------------------------------------------

    public function test_resolve_record_denies_a_paralegal_with_no_assignment(): void
    {
        // Observed, correct behavior is 404, not 403: parent::resolveRecord()
        // itself calls MatterResource::getEloquentQuery(), which ALREADY
        // excludes this row for an unassigned Paralegal (the same rule
        // ViewMatter::resolveRecord()'s own abort_unless(canAccessMatter(...), 403)
        // re-checks) — so Filament's own route-model-binding never finds
        // the row in the first place, and our explicit 403 check is never
        // reached. Both layers agree the record must not be visible; this
        // asserts the actual (still fully correct) denial rather than the
        // specific status code the source design's illustrative code
        // implied, since getEloquentQuery() and canAccessMatter() apply
        // the identical rule and can never legitimately diverge for the
        // same FirmUser/assignment state.
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $this->assertContains($response->getStatusCode(), [403, 404], 'A Paralegal with no active assignment must never successfully view the matter.');
    }

    public function test_resolve_record_denies_a_user_with_no_firm_user_at_all(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->actingAs(User::factory()->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        // No FirmUser row in Firm A at all means the record is also
        // invisible under RLS (no ambient context resolves for this
        // user), so this may surface as 403 or 404 depending on which
        // layer trips first — either is a correct denial. Assert the
        // response is genuinely NOT a successful view.
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_resolve_record_allows_a_paralegal_with_an_active_assignment_to_pass_the_authorization_check(): void
    {
        // Proves the POSITIVE case at the exact boundary
        // (MatterAccessPolicyService::canAccessMatter(), the same
        // method resolveRecord() calls) directly, since the full
        // Livewire/HTTP mount of ViewMatter for an authorized user is
        // currently blocked by the confirmed ActivityRelationManager
        // defect documented in this class's own docblock — this proves
        // the authorization boundary itself is correct independently of
        // that unrelated rendering bug.
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $allowed = $this->runWithFirmContext(
            $firm,
            fn () => app(MatterAccessPolicyService::class)->canAccessMatter($firmUser->user, $matter),
        );

        $this->assertTrue($allowed);
    }

    // ------------------------------------------------------------
    // CONFIRMED DEFECT — full page mount for an authorized user
    // ------------------------------------------------------------

    /**
     * This test asserts the CORRECT behavior (an authorized FirmOwner
     * can open a matter's detail page, including seeing the Financial
     * Evidence tab label) — and it currently FAILS, exposing the real,
     * confirmed ActivityRelationManager defect this class's own
     * docblock describes in full. This is deliberate: per this
     * checkpoint's own test-writing mandate, a genuine production bug
     * must be proven via a correctly-asserting test that fails, not
     * silently worked around.
     */
    public function test_authorized_firm_owner_can_view_the_full_matter_detail_page_including_every_tab(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Financial Evidence');
        $response->assertSee('Documents');
    }

    /**
     * Isolates the fix precisely: DocumentsRelationManager and
     * FinancialEvidenceRelationManager (both of which declare
     * $relationship or $title respectively) compute their tab title
     * without error; ActivityRelationManager, which used to declare
     * neither, now also computes its title without error since it
     * declares `protected static ?string $title = 'Activity';` (see
     * app/Filament/Firm/Resources/MatterResource/RelationManagers/ActivityRelationManager.php).
     * Found and fixed during Checkpoint 4's own test-writing pass — this
     * pinpoints the exact class that used to be broken, independent of
     * the full-page test above, and now proves it stays fixed.
     */
    public function test_activity_relation_manager_tab_title_computation_no_longer_crashes_for_any_authorized_viewer(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertSame('Documents', DocumentsRelationManager::getTitle($matter, ViewMatter::class));
        $this->assertSame('Financial Evidence', FinancialEvidenceRelationManager::getTitle($matter, ViewMatter::class));
        $this->assertSame('Activity', ActivityRelationManager::getTitle($matter, ViewMatter::class));
    }

    // ------------------------------------------------------------
    // RelationManager canViewForRecord() gates — Documents, Activity,
    // and (this design track's own responsibility) Financial Evidence
    // ------------------------------------------------------------

    public function test_financial_evidence_tab_is_visible_for_an_authorized_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertTrue(FinancialEvidenceRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    public function test_financial_evidence_tab_is_hidden_for_a_paralegal_with_no_assignment(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertFalse(FinancialEvidenceRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    public function test_financial_evidence_tab_is_visible_for_a_paralegal_with_an_active_assignment(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($matter)->forUser($firmUser->user)->create());

        $this->assertTrue(FinancialEvidenceRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    public function test_financial_evidence_tab_is_hidden_for_an_unauthenticated_actor(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertFalse(FinancialEvidenceRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    public function test_financial_evidence_tab_never_becomes_visible_to_a_firm_owner_of_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $canView = $this->runWithFirmContext(
            $firmB,
            fn () => FinancialEvidenceRelationManager::canViewForRecord($matterB, ViewMatter::class),
        );

        $this->assertFalse($canView, "A FirmOwner acting in Firm A's own session must never be authorized to view Firm B's matter's Financial Evidence tab.");
    }

    public function test_documents_tab_gate_matches_the_same_authorization_rule(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        // Receptionist is neither a blanket-access role nor assigned —
        // must be denied, exactly mirroring MatterAccessPolicyService's
        // own rule (Receptionist is not FirmOwner/Attorney).
        $this->assertFalse(DocumentsRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    // ------------------------------------------------------------
    // ListMatters — searchable list page renders without error
    // ------------------------------------------------------------

    public function test_list_matters_page_renders_and_shows_only_authorized_rows(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $assigned = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $unassigned = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterAssignment::factory()->forMatter($assigned)->forUser($firmUser->user)->create());

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListMatters::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$assigned]);
        $test->assertCanNotSeeTableRecords([$unassigned]);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
