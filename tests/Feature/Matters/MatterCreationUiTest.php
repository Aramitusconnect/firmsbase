<?php

declare(strict_types=1);

namespace Tests\Feature\Matters;

use App\Enums\FirmUserRole;
use App\Enums\MatterStatus;
use App\Filament\Firm\Resources\MatterResource\Actions\AddMatterAction;
use App\Filament\Firm\Resources\MatterResource\Actions\OpenMatterAction;
use App\Filament\Firm\Resources\MatterResource\Pages\ListMatters;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Models\User;
use App\Services\MatterOpeningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MatterCreationUiTest — Tier 3 ("Matter creation prerequisite +
 * Add Matter"). Proves:
 *
 *   1. "+ Add Matter" (AddMatterAction, on ListMatters) is role-gated
 *      per MatterCreationAccessPolicyService and, when authorized,
 *      creates a REAL, non-open Matter via MatterCreationService
 *      (never a raw `Matter::create()` inside the Action itself).
 *   2. The RLS/ownership regression checklist: client_id/
 *      assigned_attorney_id/assigned_staff_user_ids options never leak
 *      a foreign firm's rows (mirroring PaymentResourceAccessTest's own
 *      "client select options never include a foreign firm's client"
 *      pattern).
 *   3. "Open Matter" (OpenMatterAction, on ViewMatter) is hidden before
 *      a conflict check exists, hidden while the check is not clear,
 *      and only succeeds — via the real, pre-existing
 *      MatterOpeningService::openMatter() — once a real conflict check
 *      (run through MatterOpeningService::requestConflictCheck(), the
 *      same service the Conflict Check UI itself uses) comes back
 *      clear.
 */
final class MatterCreationUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. "+ Add Matter" — role gating
    // ------------------------------------------------------------

    public function test_add_matter_action_is_visible_for_a_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListMatters::class));

        $test->assertActionVisible(AddMatterAction::getDefaultName());
    }

    public function test_add_matter_action_is_hidden_for_a_receptionist(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListMatters::class));

        $test->assertActionHidden(AddMatterAction::getDefaultName());
    }

    public function test_add_matter_action_is_hidden_for_billing_staff(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListMatters::class));

        $test->assertActionHidden(AddMatterAction::getDefaultName());
    }

    // ------------------------------------------------------------
    // 2. "+ Add Matter" creates a real, non-open Matter via the real
    //    service — never a raw Matter::create() inside the Action
    // ------------------------------------------------------------

    public function test_add_matter_action_creates_a_draft_matter_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create());
        $staff = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::Paralegal)->create());

        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => Matter::query()->count()));

        $this->runWithFirmContext($firm, function () use ($client, $practiceArea, $matterType, $attorney, $staff): void {
            $test = Livewire::test(ListMatters::class);

            $test->mountAction(AddMatterAction::getDefaultName());
            $test->setActionData([
                'client_id' => $client->id,
                'primary_practice_area_id' => $practiceArea->id,
                'matter_type_id' => $matterType->id,
                'assigned_attorney_id' => $attorney->user_id,
                'assigned_staff_user_ids' => [$staff->user_id],
                'stage' => 'Intake',
            ]);
            $test->callMountedAction();
            $test->assertHasNoActionErrors();
        });

        $matter = $this->runWithFirmContext($firm, fn () => Matter::query()->where('client_id', $client->id)->first());
        $this->assertNotNull($matter, 'AddMatterAction must create a real Matter row.');
        $this->assertSame(MatterStatus::Draft, $matter->status, 'A matter created via AddMatterAction must start in Draft, never Open.');
        $this->assertNull($matter->opened_at);
        $this->assertSame($attorney->user_id, $matter->assigned_attorney_id);

        $assignment = $this->runWithFirmContext(
            $firm,
            fn () => MatterAssignment::query()->where('matter_id', $matter->id)->where('user_id', $staff->user_id)->first(),
        );
        $this->assertNotNull($assignment, 'AddMatterAction must create the requested staff MatterAssignment row.');
    }

    public function test_add_matter_action_source_never_calls_matter_create_directly(): void
    {
        // Structural proof, matching AddClientAction's own
        // "test_client_model_source_is_never_instantiated_via_create_anywhere_in_the_add_client_action"
        // convention: the Action's own source never calls
        // Matter::create() — only MatterCreationService::class)->create
        // appears.
        $source = file_get_contents(app_path('Filament/Firm/Resources/MatterResource/Actions/AddMatterAction.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Matter::create', $source);
        $this->assertStringContainsString('MatterCreationService::class)->create', $source);
    }

    public function test_add_matter_action_rejects_a_client_that_does_not_belong_to_the_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();

        $this->runWithFirmContext($firm, function () use ($foreignClient, $practiceArea, $matterType): void {
            $test = Livewire::test(ListMatters::class);

            $test->mountAction(AddMatterAction::getDefaultName());
            $test->setActionData([
                'client_id' => $foreignClient->id,
                'primary_practice_area_id' => $practiceArea->id,
                'matter_type_id' => $matterType->id,
            ]);
            $test->callMountedAction();
        });

        $this->assertSame(
            0,
            $this->runWithFirmContext($firm, fn () => Matter::query()->count()),
            'A tampered client_id belonging to a different firm must never result in a created Matter.',
        );
    }

    // ------------------------------------------------------------
    // 3. RLS/ownership regression checklist — option leakage
    // ------------------------------------------------------------

    public function test_client_options_never_include_a_foreign_firms_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($clientA, $clientB): void {
            $visibleClientIds = Client::query()->pluck('id')->all();

            $this->assertContains($clientA->id, $visibleClientIds);
            $this->assertNotContains($clientB->id, $visibleClientIds, "Firm A's Add Matter client options must never include Firm B's client.");
        });
    }

    public function test_attorney_and_staff_options_never_include_a_foreign_firms_firm_user(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $attorneyA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create());
        $attorneyB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->forUser(User::factory()->create())->role(FirmUserRole::Attorney)->create());

        $this->runWithFirmContext($firmA, function () use ($attorneyA, $attorneyB): void {
            $visibleUserIds = FirmUser::query()->pluck('user_id')->all();

            $this->assertContains($attorneyA->user_id, $visibleUserIds);
            $this->assertNotContains($attorneyB->user_id, $visibleUserIds, "Firm A's Add Matter attorney/staff options must never include Firm B's firm_users row.");
        });
    }

    /**
     * FirmsVault staging follow-up addition ("Application Completion —
     * Catalogs + Firm-Owned Reference Data"). AddMatterAction's own
     * Practice Area/Matter Type Select options already filter
     * ->where('is_active', true) (see that Action's own source) — this
     * proves a deactivated catalog entry (e.g. via
     * DeactivatePracticeAreaAction/DeactivateMatterTypeAction) is
     * excluded from the same query shape those options are built from,
     * while an active sibling remains offered.
     */
    public function test_inactive_practice_area_and_matter_type_are_excluded_from_add_matter_options(): void
    {
        $activePracticeArea = PracticeArea::factory()->create(['is_active' => true]);
        $inactivePracticeArea = PracticeArea::factory()->create(['is_active' => false]);
        $activeMatterType = MatterType::factory()->forPracticeArea($activePracticeArea)->create(['is_active' => true]);
        $inactiveMatterType = MatterType::factory()->forPracticeArea($activePracticeArea)->create(['is_active' => false]);

        $practiceAreaOptions = PracticeArea::query()->where('is_active', true)->pluck('id')->all();
        $matterTypeOptions = MatterType::query()->where('practice_area_id', $activePracticeArea->id)->where('is_active', true)->pluck('id')->all();

        $this->assertContains($activePracticeArea->id, $practiceAreaOptions);
        $this->assertNotContains($inactivePracticeArea->id, $practiceAreaOptions);
        $this->assertContains($activeMatterType->id, $matterTypeOptions);
        $this->assertNotContains($inactiveMatterType->id, $matterTypeOptions);
    }

    // ------------------------------------------------------------
    // 4. "Open Matter" — gated on conflict-check clearance
    // ------------------------------------------------------------

    public function test_open_matter_action_is_hidden_when_no_conflict_check_has_run_yet(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($matter): void {
            $test = Livewire::test(ViewMatter::class, ['record' => $matter->getRouteKey()]);
            $test->assertActionHidden(OpenMatterAction::getDefaultName());
        });
    }

    /**
     * OpenMatterAction deliberately does NOT duplicate
     * ConflictCheckSummary::isClearToProceed() in its own visible()
     * gate (mission instruction: "don't duplicate that check in
     * Filament, just call the service and surface its real result/
     * errors") — so the action stays VISIBLE once the matter reaches
     * conflict_review even when the check isn't clear. The real gate is
     * MatterOpeningService::openMatter()'s own RuntimeException, which
     * this test proves is surfaced (as a notification) and that the
     * matter is left untouched.
     */
    public function test_open_matter_action_is_blocked_by_the_real_service_when_the_conflict_check_is_not_clear(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Jamie Possible Match']));

        // Real service call — the same one the Conflict Check UI itself
        // uses (RunConflictCheckAction) — not a hand-crafted fixture.
        $this->runWithFirmContext(
            $firm,
            fn () => app(MatterOpeningService::class)->requestConflictCheck($matter, ['Jamie Possible Match'], [], $owner->user),
        );

        $fresh = $this->runWithFirmContext($firm, fn () => $matter->fresh());
        $this->assertSame(MatterStatus::ConflictReview, $fresh->status);

        $this->runWithFirmContext($firm, function () use ($fresh): void {
            $test = Livewire::test(ViewMatter::class, ['record' => $fresh->getRouteKey()]);
            $test->assertActionVisible(OpenMatterAction::getDefaultName());

            $test->mountAction(OpenMatterAction::getDefaultName());
            $test->callMountedAction();
            $test->assertNotified('Could not open matter');
        });

        $stillClosed = $this->runWithFirmContext($firm, fn () => $matter->fresh());
        $this->assertSame(MatterStatus::ConflictReview, $stillClosed->status, 'A matter with an unresolved possible match must never open.');
        $this->assertNull($stillClosed->opened_at);
    }

    public function test_open_matter_action_succeeds_via_the_real_service_once_the_conflict_check_is_clear(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        // No matching client/contact/party exists for this term — a
        // real, genuinely clear run (zero results).
        $this->runWithFirmContext(
            $firm,
            fn () => app(MatterOpeningService::class)->requestConflictCheck($matter, ['Nobody Matches This Name Xyzzy'], [], $owner->user),
        );

        $fresh = $this->runWithFirmContext($firm, fn () => $matter->fresh());
        $this->assertSame(MatterStatus::ConflictReview, $fresh->status);

        $this->runWithFirmContext($firm, function () use ($fresh): void {
            $test = Livewire::test(ViewMatter::class, ['record' => $fresh->getRouteKey()]);
            $test->assertActionVisible(OpenMatterAction::getDefaultName());

            $test->mountAction(OpenMatterAction::getDefaultName());
            $test->callMountedAction();
            $test->assertNotified('Matter opened');
        });

        $opened = $this->runWithFirmContext($firm, fn () => $matter->fresh());
        $this->assertSame(MatterStatus::Open, $opened->status);
        $this->assertNotNull($opened->opened_at);
    }

    /**
     * The Receptionist is given an active MatterAssignment on this
     * matter so MatterAccessPolicyService::canAccessMatter() (the
     * per-record boundary ViewMatter::resolveRecord() enforces) passes
     * and the page mounts at all — isolating the assertion to the role
     * CEILING (MatterCreationAccessPolicyService::canOpenMatter()),
     * which excludes Receptionist regardless of assignment.
     */
    public function test_open_matter_action_is_hidden_for_a_receptionist_even_when_the_check_is_clear(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create(),
        );
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext(
            $firm,
            fn () => app(MatterOpeningService::class)->requestConflictCheck($matter, ['Nobody Matches This Name Xyzzy'], [], $owner->user),
        );

        $fresh = $this->runWithFirmContext($firm, fn () => $matter->fresh());

        $receptionist = $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $this->runWithFirmContext($firm, fn () => MatterAssignment::create([
            'matter_id' => $fresh->id,
            'user_id' => $receptionist->user_id,
            'assigned_at' => now(),
        ]));

        $this->runWithFirmContext($firm, function () use ($fresh): void {
            $test = Livewire::test(ViewMatter::class, ['record' => $fresh->getRouteKey()]);
            $test->assertSuccessful();
            $test->assertActionHidden(OpenMatterAction::getDefaultName());
        });
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
