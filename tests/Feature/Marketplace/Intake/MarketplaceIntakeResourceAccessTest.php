<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Enums\FirmUserRole;
use App\Enums\MarketplaceIntakeStatus;
use App\Filament\Firm\Resources\MarketplaceIntakeResource;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\AcceptIntakeAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\ClearIntakeConflictReviewAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\ConvertIntakeAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\DeclineIntakeAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\MarkUnderReviewAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions\RunIntakeConflictCheckAction;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Pages\ListMarketplaceIntakes;
use App\Filament\Firm\Resources\MarketplaceIntakeResource\Pages\ViewMarketplaceIntake;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PracticeArea;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 9 — the
 * first Firm-authenticated UI this mission has built. Proves role
 * ceilings (reused from ClientCrmAccessPolicyService), cross-firm
 * isolation, and that the review-transition actions only ever appear
 * for the status they're valid in.
 */
final class MarketplaceIntakeResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }

    private function freshIntake(Firm $firm): MarketplaceIntake
    {
        $directoryFirm = $this->runWithFirmContext($firm, fn () => DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]));

        return app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm);
    }

    public function test_every_role_can_view_the_resource(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(MarketplaceIntakeResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_the_resource_has_no_create_page(): void
    {
        $pages = MarketplaceIntakeResource::getPages();

        $this->assertArrayNotHasKey('create', $pages, 'A MarketplaceIntake must never be Firm-created — it is always visitor-created via the public intake flow.');
    }

    public function test_list_page_shows_only_this_firms_intakes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $intakeA = $this->freshIntake($firmA);
        $intakeB = $this->freshIntake($firmB);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListMarketplaceIntakes::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$intakeA]);
        $test->assertCanNotSeeTableRecords([$intakeB]);
    }

    public function test_direct_url_guess_of_another_firms_intake_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $intakeB = $this->freshIntake($firmB);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(MarketplaceIntakeResource::getUrl('view', ['record' => $intakeB])));

        $response->assertNotFound();
    }

    public function test_mark_under_review_action_is_visible_only_for_a_submitted_intake(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->assertActionVisible(MarkUnderReviewAction::getDefaultName());
        });
    }

    public function test_mark_under_review_action_is_hidden_for_a_started_intake(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $intake = $this->freshIntake($firm);

        $this->runWithFirmContext($firm, function () use ($intake): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $intake->getRouteKey()]);
            $test->assertActionHidden(MarkUnderReviewAction::getDefaultName());
        });
    }

    public function test_mark_under_review_action_is_hidden_for_billing_staff(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->assertActionHidden(MarkUnderReviewAction::getDefaultName());
        });
    }

    public function test_mark_under_review_action_transitions_the_status_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->callAction(MarkUnderReviewAction::getDefaultName());
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $submitted->fresh());
        $this->assertSame(MarketplaceIntakeStatus::UnderReview, $fresh->status);
    }

    public function test_run_conflict_check_action_is_visible_only_when_under_review(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);
        $underReview = app(MarketplaceIntakeService::class)->markUnderReview($firm, $submitted);

        $this->runWithFirmContext($firm, function () use ($underReview): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $underReview->getRouteKey()]);
            $test->assertActionVisible(RunIntakeConflictCheckAction::getDefaultName());
            $test->assertActionHidden(ClearIntakeConflictReviewAction::getDefaultName());
        });
    }

    public function test_clear_conflict_review_action_is_hidden_for_a_paralegal_but_visible_for_a_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);
        $underReview = app(MarketplaceIntakeService::class)->markUnderReview($firm, $submitted);
        $flagged = app(MarketplaceIntakeService::class)->markConflictReviewRequired($firm, $underReview);

        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, function () use ($flagged): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $flagged->getRouteKey()]);
            $test->assertActionHidden(ClearIntakeConflictReviewAction::getDefaultName());
        });

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->runWithFirmContext($firm, function () use ($flagged): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $flagged->getRouteKey()]);
            $test->assertActionVisible(ClearIntakeConflictReviewAction::getDefaultName());
        });
    }

    // ---------------------------------------------------------------
    // Mission 3, checkpoint 10 — Accept / Decline
    // ---------------------------------------------------------------

    public function test_accept_action_is_hidden_for_a_receptionist_but_visible_for_a_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->assertActionHidden(AcceptIntakeAction::getDefaultName());
        });

        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->assertActionVisible(AcceptIntakeAction::getDefaultName());
        });
    }

    public function test_accept_action_is_hidden_while_conflict_review_is_required(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);
        $underReview = app(MarketplaceIntakeService::class)->markUnderReview($firm, $submitted);
        $flagged = app(MarketplaceIntakeService::class)->markConflictReviewRequired($firm, $underReview);

        $this->runWithFirmContext($firm, function () use ($flagged): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $flagged->getRouteKey()]);
            $test->assertActionHidden(AcceptIntakeAction::getDefaultName());
        });
    }

    public function test_accept_action_forced_while_conflict_review_is_required_still_refuses_server_side(): void
    {
        // Mission 3, checkpoint 15 (adversarial audit). visible() is a
        // UI nicety, not the security boundary — this proves the
        // action's own action() closure (which calls
        // MarketplaceIntakeService::markAccepted(), itself refusing
        // any status other than Submitted/UnderReview) refuses even
        // when called directly, bypassing the hidden button.
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);
        $underReview = app(MarketplaceIntakeService::class)->markUnderReview($firm, $submitted);
        $flagged = app(MarketplaceIntakeService::class)->markConflictReviewRequired($firm, $underReview);

        $this->runWithFirmContext($firm, function () use ($flagged): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $flagged->getRouteKey()]);
            $test->mountAction(AcceptIntakeAction::getDefaultName());
            $test->callMountedAction();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $flagged->fresh());
        $this->assertSame(MarketplaceIntakeStatus::ConflictReviewRequired, $fresh->status, 'Forcing the hidden Accept action must never bypass the conflict-review gate.');
    }

    public function test_accept_action_forced_by_a_receptionist_still_refuses_server_side(): void
    {
        // Mission 3, checkpoint 15 (adversarial audit) — role ceiling
        // enforced inside action(), not only via visible().
        $firm = Firm::factory()->create();
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->mountAction(AcceptIntakeAction::getDefaultName());
            $test->callMountedAction();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $submitted->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Submitted, $fresh->status, 'Forcing the hidden Accept action as a Receptionist must never bypass the role ceiling.');
    }

    public function test_accept_action_transitions_the_status_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->callAction(AcceptIntakeAction::getDefaultName());
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $submitted->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Accepted, $fresh->status);
    }

    public function test_decline_action_is_visible_even_during_conflict_review_required(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);
        $underReview = app(MarketplaceIntakeService::class)->markUnderReview($firm, $submitted);
        $flagged = app(MarketplaceIntakeService::class)->markConflictReviewRequired($firm, $underReview);

        $this->runWithFirmContext($firm, function () use ($flagged): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $flagged->getRouteKey()]);
            $test->assertActionVisible(DeclineIntakeAction::getDefaultName());
        });
    }

    public function test_decline_action_requires_a_reason_and_transitions_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->mountAction(DeclineIntakeAction::getDefaultName());
            $test->setActionData(['decline_reason' => '']);
            $test->callMountedAction();
            $test->assertHasActionErrors(['decline_reason']);
        });

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->mountAction(DeclineIntakeAction::getDefaultName());
            $test->setActionData(['decline_reason' => 'Outside our practice areas.']);
            $test->callMountedAction();
            $test->assertHasNoActionErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $submitted->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Declined, $fresh->status);
        $this->assertSame('Outside our practice areas.', $fresh->decline_reason);
    }

    // ---------------------------------------------------------------
    // Mission 3, checkpoint 11 — ConvertIntakeAction
    // ---------------------------------------------------------------

    private function acceptedIntakeWithPracticeArea(Firm $firm, PracticeArea $practiceArea): MarketplaceIntake
    {
        $directoryFirm = $this->runWithFirmContext($firm, fn () => DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]));
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm, $practiceArea);
        $this->runWithFirmContext($firm, fn () => $intake->update([
            'prospect_name' => 'Jordan Prospect',
            'prospect_email' => 'jordan@example.com',
            'prospect_phone' => '555-0100',
        ]));
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        return app(MarketplaceIntakeService::class)->markAccepted($firm, $submitted);
    }

    public function test_convert_action_is_hidden_for_a_receptionist_but_visible_for_a_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();

        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $accepted = $this->acceptedIntakeWithPracticeArea($firm, $practiceArea);
        $this->runWithFirmContext($firm, function () use ($accepted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $accepted->getRouteKey()]);
            $test->assertActionHidden(ConvertIntakeAction::getDefaultName());
        });

        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, function () use ($accepted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $accepted->getRouteKey()]);
            $test->assertActionVisible(ConvertIntakeAction::getDefaultName());
        });
    }

    public function test_convert_action_forced_by_a_receptionist_still_refuses_server_side(): void
    {
        // Mission 3, checkpoint 15 (adversarial audit). visible() hides
        // the button, but the real boundary is action()'s own re-check
        // of ClientCrmAccessPolicyService::canConvertLead() — proves a
        // forced call (bypassing the hidden button entirely) creates
        // no Matter and leaves the intake untouched.
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $accepted = $this->acceptedIntakeWithPracticeArea($firm, $practiceArea);

        $this->runWithFirmContext($firm, function () use ($accepted, $matterType): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $accepted->getRouteKey()]);
            $test->mountAction(ConvertIntakeAction::getDefaultName());
            $test->setActionData(['matter_type_id' => $matterType->id]);
            $test->callMountedAction();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $accepted->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Accepted, $fresh->status, 'Forcing the hidden Convert action as a Receptionist must never bypass the role ceiling.');
        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => Matter::query()->where('firm_id', $firm->id)->count()));
    }

    public function test_convert_action_is_hidden_unless_the_intake_is_accepted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $intake = $this->freshIntake($firm);
        $submitted = app(MarketplaceIntakeService::class)->markSubmitted($firm, $intake);

        $this->runWithFirmContext($firm, function () use ($submitted): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $submitted->getRouteKey()]);
            $test->assertActionHidden(ConvertIntakeAction::getDefaultName());
        });
    }

    public function test_convert_action_creates_a_matter_and_transitions_the_intake_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->forPracticeArea($practiceArea)->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $accepted = $this->acceptedIntakeWithPracticeArea($firm, $practiceArea);

        $this->runWithFirmContext($firm, function () use ($accepted, $matterType): void {
            $test = Livewire::test(ViewMarketplaceIntake::class, ['record' => $accepted->getRouteKey()]);
            $test->mountAction(ConvertIntakeAction::getDefaultName());
            $test->setActionData(['matter_type_id' => $matterType->id]);
            $test->callMountedAction();
            $test->assertHasNoActionErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => $accepted->fresh());
        $this->assertSame(MarketplaceIntakeStatus::Converted, $fresh->status);

        $matter = $this->runWithFirmContext($firm, fn () => Matter::query()->where('firm_id', $firm->id)->sole());
        $this->assertSame($matterType->id, $matter->matter_type_id);
        $this->assertSame($fresh->converted_client_id, $matter->client_id);
    }
}
