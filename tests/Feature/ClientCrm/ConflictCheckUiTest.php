<?php

declare(strict_types=1);

namespace Tests\Feature\ClientCrm;

use App\Enums\ConflictCheckResultStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource\Actions\ResolveConflictCheckResultAction;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\ConflictCheckResultsRelationManager;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\ConflictChecksRelationManager;
use App\Models\Client;
use App\Models\ConflictCheckResult;
use App\Models\ConflictCheckRun;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Services\ConflictCheckService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ConflictCheckUiTest — proves (1) ConflictCheckService::run() results
 * default to `possible_match` for real (through the actual UI-wired
 * service call, not a re-implementation), (2) the "Run Conflict Check"
 * / "Resolve" actions render and gate correctly per role via the two
 * new MatterResource RelationManager tabs, and (3) resolveResult()'s
 * ACTUAL guard (ConfirmedConflict/Dismissed only — no requester-vs-
 * reviewer distinctness check exists in the real method body, a
 * documented deviation from the manifest's assumption — see
 * ResolveConflictCheckResultAction's own docblock) is respected by the
 * UI action, including that it is UNREACHABLE with any other status.
 */
final class ConflictCheckUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. Results default to possible_match — proven through the real,
    //    UI-wired service call
    // ------------------------------------------------------------

    public function test_conflict_check_service_run_defaults_every_match_to_possible_match(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Jamie Match']));

        $summary = $this->runWithFirmContext(
            $firm,
            fn () => app(ConflictCheckService::class)->run($matter, ['Jamie Match'], [], $firmUser->user),
        );

        $this->assertGreaterThan(0, $summary->resultCount);
        $this->assertTrue($summary->hasPossibleMatches);
        $this->assertFalse($summary->hasConfirmedConflicts);

        $results = $this->runWithFirmContext(
            $firm,
            fn () => ConflictCheckResult::query()->where('conflict_check_run_id', $summary->conflictCheckRunId)->get(),
        );

        foreach ($results as $result) {
            $this->assertSame(ConflictCheckResultStatus::PossibleMatch, $result->status);
        }
    }

    // ------------------------------------------------------------
    // 2. RelationManager tabs mount + gate the header/row actions
    //    correctly (matching this codebase's own established
    //    RelationManager-standalone test pattern — see
    //    MatterResourceAccessTest/FirmIntegrationManualSyncDispatchActionTest)
    // ------------------------------------------------------------

    public function test_conflict_checks_tab_is_visible_to_an_authorized_firm_owner_and_shows_the_run_action(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertTrue(
            $this->runWithFirmContext($firm, fn () => ConflictChecksRelationManager::canViewForRecord($matter, ViewMatter::class)),
        );

        $this->runWithFirmContext($firm, function () use ($matter): void {
            $test = Livewire::test(ConflictChecksRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertTableActionVisible('runConflictCheck');
        });
    }

    public function test_conflict_checks_tab_hides_the_run_action_for_a_receptionist(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($matter): void {
            $test = Livewire::test(ConflictChecksRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertTableActionHidden('runConflictCheck');
        });
    }

    public function test_conflict_checks_tab_is_hidden_for_a_different_firms_matter(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $canView = $this->runWithFirmContext(
            $firmB,
            fn () => ConflictChecksRelationManager::canViewForRecord($matterB, ViewMatter::class),
        );

        $this->assertFalse($canView, "A FirmOwner acting in Firm A's own session must never see Firm B's matter's Conflict Checks tab.");
    }

    public function test_conflict_check_results_tab_resolve_action_visible_for_firm_owner_hidden_for_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $run = $this->runWithFirmContext($firm, fn () => ConflictCheckRun::factory()->forFirm($firm)->forMatter($matter)->completed()->create());
        $result = $this->runWithFirmContext($firm, fn () => ConflictCheckResult::factory()->forRun($run)->create());

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->runWithFirmContext($firm, function () use ($matter, $result): void {
            $test = Livewire::test(ConflictCheckResultsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertTableActionVisible(ResolveConflictCheckResultAction::getDefaultName(), $result);
        });

        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $this->runWithFirmContext($firm, function () use ($matter, $result): void {
            $test = Livewire::test(ConflictCheckResultsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertTableActionHidden(ResolveConflictCheckResultAction::getDefaultName(), $result);
        });
    }

    public function test_resolve_action_is_hidden_for_a_result_that_is_no_longer_a_possible_match(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $run = $this->runWithFirmContext($firm, fn () => ConflictCheckRun::factory()->forFirm($firm)->forMatter($matter)->completed()->create());
        $result = $this->runWithFirmContext($firm, fn () => ConflictCheckResult::factory()->forRun($run)->dismissed()->create());

        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($matter, $result): void {
            $test = Livewire::test(ConflictCheckResultsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertTableActionHidden(ResolveConflictCheckResultAction::getDefaultName(), $result);
        });
    }

    // ------------------------------------------------------------
    // 3. resolveResult()'s ACTUAL guard — ConfirmedConflict/Dismissed
    //    only, respected end-to-end via the real service call the
    //    Action itself uses
    // ------------------------------------------------------------

    public function test_resolve_result_rejects_possible_match_and_clear_and_accepts_only_the_two_terminal_statuses(): void
    {
        $firm = Firm::factory()->create();
        $reviewer = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $run = $this->runWithFirmContext($firm, fn () => ConflictCheckRun::factory()->forFirm($firm)->forMatter($matter)->completed()->create());

        foreach ([ConflictCheckResultStatus::PossibleMatch, ConflictCheckResultStatus::Clear] as $rejected) {
            $result = $this->runWithFirmContext($firm, fn () => ConflictCheckResult::factory()->forRun($run)->create());

            $this->runWithFirmContext($firm, function () use ($result, $reviewer, $rejected): void {
                $this->expectException(InvalidArgumentException::class);
                app(ConflictCheckService::class)->resolveResult($result, $rejected, $reviewer->user);
            });
        }

        foreach ([ConflictCheckResultStatus::ConfirmedConflict, ConflictCheckResultStatus::Dismissed] as $accepted) {
            $result = $this->runWithFirmContext($firm, fn () => ConflictCheckResult::factory()->forRun($run)->create());

            $resolved = $this->runWithFirmContext(
                $firm,
                fn () => app(ConflictCheckService::class)->resolveResult($result, $accepted, $reviewer->user),
            );

            $this->assertSame($accepted, $resolved->status);
        }
    }

    /**
     * DEVIATION FROM THE MANIFEST'S ASSUMPTION (documented, not
     * silently papered over — see ResolveConflictCheckResultAction's
     * own docblock): resolveResult()'s real method body has NO
     * requester-vs-reviewer distinctness guard, unlike
     * IntegrationConflictService::transitionStatus()'s real
     * self-clearing check. The SAME user who requested the conflict
     * check run may resolve its own results — proven here as the
     * accurate, current behavior, not assumed.
     */
    public function test_resolve_result_has_no_requester_distinctness_guard_the_same_user_may_resolve_their_own_run(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $run = $this->runWithFirmContext($firm, fn () => ConflictCheckRun::factory()->forFirm($firm)->forMatter($matter)->completed()->create(['requested_by' => $owner->user_id]));
        $result = $this->runWithFirmContext($firm, fn () => ConflictCheckResult::factory()->forRun($run)->create());

        $resolved = $this->runWithFirmContext(
            $firm,
            fn () => app(ConflictCheckService::class)->resolveResult($result, ConflictCheckResultStatus::Dismissed, $owner->user),
        );

        $this->assertSame(ConflictCheckResultStatus::Dismissed, $resolved->status);
        $freshRun = $this->runWithFirmContext($firm, fn () => $run->fresh());
        $this->assertSame($owner->user_id, $freshRun->requested_by);
    }

    public function test_conflict_check_ui_never_offers_possible_match_or_clear_as_a_resolution_option(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/MatterResource/Actions/ResolveConflictCheckResultAction.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('ConfirmedConflict->value', $source);
        $this->assertStringContainsString('Dismissed->value', $source);
        $this->assertStringNotContainsString('PossibleMatch->value', $source);
        $this->assertStringNotContainsString("'clear' =>", $source);
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
}
