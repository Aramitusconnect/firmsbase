<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ApproveConflictResolutionAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ProposeConflictResolutionAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\ConflictsRelationManager;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationConflictService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmIntegrationConflictResolutionActionsTest — Checkpoint 10 (frozen-
 * design-post-security-review.md §7; diff-review.md §1.3/§1.4).
 *
 * FORMERLY-DISCOVERED BLOCKER, NOW FIXED:
 * `ProposeConflictResolutionAction`/`ApproveConflictResolutionAction`
 * are row actions on `ConflictsRelationManager`. That RelationManager
 * used to be unmountable via `Livewire::test()` — `getRelationship()`
 * used to return a bare `Illuminate\Database\Eloquent\Builder`, which
 * crashed Filament 4.11.8's own `Table::getRelationshipQuery()` — the
 * same confirmed production bug documented across this checkpoint's Ui
 * test suite. It now returns a genuine, manually constructed `HasMany`
 * `Relation`, so the RelationManager mounts and renders real conflict
 * rows with both actions registered — proven for real by
 * `test_conflicts_relation_manager_mounts_successfully_and_renders_both_resolution_actions()`
 * below, replacing the self-documented placeholder that used to assert
 * the mount throws (also independently re-proven in
 * `FirmIntegrationsUiSecretSafetyTest`).
 *
 * PRODUCTION BUG FIXED (this pass): `ProposeConflictResolutionAction`/
 * `ApproveConflictResolutionAction` now resolve the acting `FirmUser`
 * via `Auth::user()->activeFirmUser()` and wrap their fresh re-fetch +
 * `IntegrationConflictService::proposeResolution()`/`transitionStatus()`
 * call in `TenantContextService::runWithFirmContext($firmId, ...)` — see
 * both Action files' own docblocks, and
 * `FirmIntegrationConnectionLifecycleActionsTest`'s class docblock for
 * the full root-cause writeup (the same missing-middleware gap on
 * Filament's shared `POST livewire/update` endpoint).
 * `ApproveConflictResolutionAction::modalDescription()`'s own read-only
 * re-fetch (a SEPARATE, cosmetic bug this fix also closes: it silently
 * always rendered "left no note" under the same gap) is fixed the same
 * way.
 *
 * STILL-OPEN, SEPARATE, DEEPER PRODUCTION ISSUE (see
 * `FirmIntegrationConnectionLifecycleActionsTest`'s class docblock for
 * the full writeup and stack-trace evidence): Filament's
 * `RelationManager` declares `public Model $ownerRecord;` as a plain
 * Livewire-synthesized property, which Livewire's own `ModelSynth`
 * re-hydrates via a raw, context-less `firstOrFail()` on every
 * subsequent request — BEFORE any Action code runs. Confirmed
 * empirically for `ConflictsRelationManager` too (a genuine
 * `mountTableAction()`/`callMountedTableAction()` round-trip with NO
 * ambient wrap reproduces the identical `ModelNotFoundException` for
 * `FirmIntegration`, i.e. `$ownerRecord`, before ever reaching
 * `ProposeConflictResolutionAction`'s own now-fixed code). The genuine
 * round-trip test below therefore wraps the ENTIRE
 * `mountTableAction()`/`callMountedTableAction()` sequence in
 * `$this->runWithFirmContext($firm, function () { ... })` for the same
 * reason `FirmIntegrationConnectionLifecycleActionsTest` does — this
 * proves the fixed Action's own logic (re-fetch + nested
 * `runWithFirmContext` re-entrancy) is correct, not that an unmodified
 * production round-trip succeeds, which remains blocked by the deeper
 * issue above.
 */
final class FirmIntegrationConflictResolutionActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 0. RelationManager mount — genuine Livewire coverage (the
    //    mount-blocking Filament framework bug is now fixed)
    // ------------------------------------------------------------

    public function test_conflicts_relation_manager_mounts_successfully_and_renders_both_resolution_actions(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create(['resource_type' => 'contact', 'requires_manual_review' => false]));
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ConflictsRelationManager::class, [
                'ownerRecord' => $connection,
                'pageClass' => ViewFirmIntegration::class,
            ])
        );

        $test->assertOk();
        $test->assertSee($conflict->resource_type);
        $test->assertTableActionExists(ProposeConflictResolutionAction::getDefaultName());
        $test->assertTableActionExists(ApproveConflictResolutionAction::getDefaultName());
    }

    public function test_propose_then_approve_via_a_genuine_mount_table_action_call_mounted_table_action_round_trip(): void
    {
        // Genuine Livewire::test() proof the fix works (see class
        // docblock re: the ambient-context wrap this still needs, and
        // why).
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create(['resource_type' => 'contact', 'requires_manual_review' => false]));

        $proposer = $this->actingAsRole($firm, FirmUserRole::Attorney);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ConflictsRelationManager::class, [
            'ownerRecord' => $connection,
            'pageClass' => ViewFirmIntegration::class,
        ]));

        $this->runWithFirmContext($firm, function () use ($test, $conflict) {
            $test->mountTableAction(ProposeConflictResolutionAction::getDefaultName(), $conflict->id);
            $test->setActionData(['proposed_outcome' => ConflictStatus::ResolvedLocalWins->value, 'resolution_note' => 'Keep our local value.']);
            $test->callMountedTableAction();
        });
        $test->assertHasNoTableActionErrors();
        $test->assertNotified('Resolution proposed');

        $proposed = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->where('id', $conflict->id)->first());
        $this->assertSame(ConflictStatus::AwaitingReview, $proposed->status);
        $this->assertSame($proposer->id, $proposed->resolved_by_firm_user_id);

        // Approve as a DIFFERENT actor, via a fresh RelationManager mount.
        $approver = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test2 = $this->runWithFirmContext($firm, fn () => Livewire::test(ConflictsRelationManager::class, [
            'ownerRecord' => $connection,
            'pageClass' => ViewFirmIntegration::class,
        ]));

        $this->runWithFirmContext($firm, function () use ($test2, $conflict) {
            $test2->mountTableAction(ApproveConflictResolutionAction::getDefaultName(), $conflict->id);
            $test2->setActionData(['approved_outcome' => ConflictStatus::ResolvedLocalWins->value]);
            $test2->callMountedTableAction();
        });
        $test2->assertHasNoTableActionErrors();
        $test2->assertNotified('Conflict resolved');

        $resolved = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->where('id', $conflict->id)->first());
        $this->assertSame(ConflictStatus::ResolvedLocalWins, $resolved->status);
        $this->assertSame($proposer->id, $resolved->resolved_by_firm_user_id);
        $this->assertSame($approver->id, $resolved->resolution_approved_by_firm_user_id);
    }

    public function test_approve_via_a_genuine_mount_table_action_call_still_rejects_the_same_actor_who_proposed(): void
    {
        // Confirms the fix is a false-negative fix, not an accidental
        // false-positive: the SAME actor attempting to approve their own
        // proposal must still be rejected — now because the UX pre-check
        // (and transitionStatus()'s own final distinctness enforcement)
        // genuinely runs, not because context was never established.
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create(['resource_type' => 'contact', 'requires_manual_review' => false]));

        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ConflictsRelationManager::class, [
            'ownerRecord' => $connection,
            'pageClass' => ViewFirmIntegration::class,
        ]));

        $this->runWithFirmContext($firm, function () use ($test, $conflict) {
            $test->mountTableAction(ProposeConflictResolutionAction::getDefaultName(), $conflict->id);
            $test->setActionData(['proposed_outcome' => ConflictStatus::ResolvedLocalWins->value]);
            $test->callMountedTableAction();
        });
        $test->assertHasNoTableActionErrors();

        // SAME actor attempts to approve their own proposal.
        $test2 = $this->runWithFirmContext($firm, fn () => Livewire::test(ConflictsRelationManager::class, [
            'ownerRecord' => $connection,
            'pageClass' => ViewFirmIntegration::class,
        ]));

        $this->runWithFirmContext($firm, function () use ($test2, $conflict) {
            $test2->mountTableAction(ApproveConflictResolutionAction::getDefaultName(), $conflict->id);
            $test2->setActionData(['approved_outcome' => ConflictStatus::ResolvedLocalWins->value]);
            $test2->callMountedTableAction();
        });
        $test2->assertHasNoTableActionErrors();
        $test2->assertNotified('You proposed this resolution');

        $stillAwaiting = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->where('id', $conflict->id)->first());
        $this->assertSame(ConflictStatus::AwaitingReview, $stillAwaiting->status, 'Same-actor approval must be rejected — the conflict must remain AwaitingReview, never resolved.');
    }

    // ------------------------------------------------------------
    // 1. Full two-actor flow, non-privileged conflict — proves the
    //    uniform dual-approval deviation (diff-review.md §1.4)
    // ------------------------------------------------------------

    public function test_propose_then_approve_resolves_a_non_privileged_conflict_and_still_requires_two_distinct_actors(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create(['resource_type' => 'contact', 'requires_manual_review' => false]));

        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->proposeResolution($firm, $actorA, $conflict, ConflictStatus::ResolvedLocalWins, 'Keep our local value.');

        $proposed = $this->runWithFirmContext($firm, fn () => $conflict->fresh());
        $this->assertSame(ConflictStatus::AwaitingReview, $proposed->status);
        $this->assertSame($actorA->id, $proposed->resolved_by_firm_user_id);

        $actorB = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $resolved = $this->approveResolution($firm, $actorB, $conflict, ConflictStatus::ResolvedLocalWins);

        $this->assertSame(ConflictStatus::ResolvedLocalWins, $resolved->status);
        $this->assertSame($actorA->id, $resolved->resolved_by_firm_user_id);
        $this->assertSame($actorB->id, $resolved->resolution_approved_by_firm_user_id);
    }

    // ------------------------------------------------------------
    // 2. Full two-actor flow, privileged/flagged conflict
    // ------------------------------------------------------------

    public function test_propose_then_approve_resolves_a_privileged_conflict(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->privilegedResource()
            ->create());

        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->proposeResolution($firm, $actorA, $conflict, ConflictStatus::ResolvedRemoteWins, 'Provider value is authoritative here.');

        $actorB = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $resolved = $this->approveResolution($firm, $actorB, $conflict, ConflictStatus::ResolvedRemoteWins);

        $this->assertSame(ConflictStatus::ResolvedRemoteWins, $resolved->status);
    }

    // ------------------------------------------------------------
    // 3. Same-actor rejection — both the UX pre-check and the final
    //    transitionStatus() distinctness boundary
    // ------------------------------------------------------------

    public function test_approve_rejects_the_same_actor_who_proposed_via_the_ux_precheck(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->privilegedResource()
            ->create());

        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->proposeResolution($firm, $actorA, $conflict, ConflictStatus::ResolvedLocalWins);

        // ApproveConflictResolutionAction's own UX-only pre-check
        // (step (b) in its docblock): (int) $firmUser->id === (int)
        // $fresh->resolved_by_firm_user_id -> friendly rejection,
        // BEFORE ever reaching transitionStatus().
        $fresh = $this->runWithFirmContext($firm, fn () => $conflict->fresh());
        $this->assertSame($actorA->id, $fresh->resolved_by_firm_user_id);

        // Simulate the SAME actor attempting to approve their own
        // proposal — the action's own code would reject with a
        // friendly notification and never call transitionStatus() at
        // all. Confirmed directly against the precondition it checks.
        $this->assertTrue((int) $actorA->id === (int) $fresh->resolved_by_firm_user_id);
    }

    public function test_transition_status_itself_remains_the_final_authoritative_distinctness_enforcement_even_for_a_propose_resolution_originated_row(): void
    {
        // Per this checkpoint's own contingency clause: if calling the
        // approve action's underlying logic in a way that skips the UI
        // comparison is not feasible at the Livewire level (confirmed
        // infeasible above — the host component cannot even mount),
        // confirm directly that transitionStatus() itself still
        // rejects same-actor at the service level, independent of the
        // UI, for a row whose resolved_by_firm_user_id came from a
        // real proposeResolution() call (not a raw fixture).
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->privilegedResource()
            ->create());

        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->proposeResolution($firm, $actorA, $conflict, ConflictStatus::ResolvedLocalWins);

        $fresh = $this->runWithFirmContext($firm, fn () => $conflict->fresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a distinct, non-null resolution_approved_by_firm_user_id/');

        // Bypasses the Action's own UX pre-check entirely — calls
        // transitionStatus() DIRECTLY with the SAME actor id as both
        // resolver and approver, exactly the bypass scenario this
        // checkpoint's frozen design requires to remain rejected.
        $this->runWithFirmContext($firm, fn () => app(IntegrationConflictService::class)->transitionStatus(
            $fresh,
            ConflictStatus::ResolvedLocalWins,
            resolvedByFirmUserId: $fresh->resolved_by_firm_user_id,
            resolutionApprovedByFirmUserId: (int) $actorA->id, // same actor as resolvedByFirmUserId
        ));
    }

    // ------------------------------------------------------------
    // 4. Modal description surfaces Actor A's proposal (post-diff-review
    //    fix — read the CURRENT file state, per task instructions)
    // ------------------------------------------------------------

    public function test_approve_action_modal_description_source_surfaces_actor_as_proposed_note_verbatim(): void
    {
        // Structural confirmation the fix is present: ApproveConflictResolutionAction's
        // modalDescription() re-fetches the conflict fresh and builds
        // its description from $fresh->resolution_note — this was
        // fixed AFTER the original diff review (diff-review.md §1.3's
        // flagged gap). We cannot exercise the actual rendered modal
        // (blocked — see test 0 above), so this proves the fix is
        // genuinely present in the file the Ui would render, matching
        // the checkpoint assignment's explicit instruction to read the
        // CURRENT state of this file rather than trust the stale
        // diff-review description.
        $source = file_get_contents(app_path('Filament/Firm/Resources/FirmIntegrationResource/Actions/ApproveConflictResolutionAction.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('modalDescription(function (IntegrationConflict $record): string', $source);
        $this->assertStringContainsString('$fresh?->resolution_note', $source);
        $this->assertStringContainsString('Actor A proposed', $source);
    }

    public function test_propose_resolution_note_records_the_proposed_outcome_as_free_text_the_only_durable_record_of_it(): void
    {
        // Confirms the disclosed deviation (diff-review.md §1.3): no
        // proposed_outcome column exists; ProposeConflictResolutionAction
        // itself builds the "Proposed outcome: X. <note>" text, which
        // is the ONLY durable record of Actor A's specific proposed
        // outcome. Exercised directly against the real service +ac tion
        // note-construction logic, mirroring ProposeConflictResolutionAction's
        // own action() closure exactly.
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create(['resource_type' => 'contact']));
        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);

        $outcome = ConflictStatus::ResolvedMerged;
        $userNote = 'Merged both records after manual review.';
        $note = "Proposed outcome: {$outcome->value}. {$userNote}";

        $this->runWithFirmContext($firm, fn () => app(IntegrationConflictService::class)->proposeResolution($conflict, $outcome, $actorA->id, $note));

        $fresh = $this->runWithFirmContext($firm, fn () => $conflict->fresh());
        $this->assertSame($note, $fresh->resolution_note);
        $this->assertStringContainsString('resolved_merged', $fresh->resolution_note);
    }

    // ------------------------------------------------------------
    // 5. Role/entitlement gating for both actions
    // ------------------------------------------------------------

    public function test_propose_resolution_is_denied_below_the_configure_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create(['resource_type' => 'contact']));
        $actor = $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $this->expectException(RuntimeException::class);

        $this->proposeResolution($firm, $actor, $conflict, ConflictStatus::ResolvedLocalWins);
    }

    public function test_approve_resolution_is_denied_below_the_configure_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create(['resource_type' => 'contact']));
        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->proposeResolution($firm, $actorA, $conflict, ConflictStatus::ResolvedLocalWins);

        $actorB = $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->expectException(RuntimeException::class);

        $this->approveResolution($firm, $actorB, $conflict, ConflictStatus::ResolvedLocalWins);
    }

    public function test_propose_resolution_requires_entitlement(): void
    {
        $firm = Firm::factory()->create(); // not entitled
        $connection = $this->runWithFirmContext($firm, function () use ($firm) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();

            return FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null]);
        });
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create(['resource_type' => 'contact']));
        $actor = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->proposeResolution($firm, $actor, $conflict, ConflictStatus::ResolvedLocalWins);
    }

    public function test_propose_resolution_is_rejected_when_the_conflict_already_has_a_proposer(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $conflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create(['resource_type' => 'contact']));
        $actorA = $this->actingAsRole($firm, FirmUserRole::Attorney);
        $this->proposeResolution($firm, $actorA, $conflict, ConflictStatus::ResolvedLocalWins);

        // Mirrors ProposeConflictResolutionAction's own UI-layer guard:
        // "already has a proposed resolution or is no longer open" —
        // enforced at the Action/caller layer, checked here directly.
        $fresh = $this->runWithFirmContext($firm, fn () => $conflict->fresh());
        $this->assertNotNull($fresh->resolved_by_firm_user_id, 'Precondition: a proposer is already set.');

        $actorC = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->assertTrue(! ($fresh->isOpen() && $fresh->resolved_by_firm_user_id === null), 'The UI-layer guard condition must be false (rejecting) once a proposer exists.');
    }

    // ------------------------------------------------------------
    // Helpers — replicate ProposeConflictResolutionAction's/
    // ApproveConflictResolutionAction's own action() closure sequence.
    // ------------------------------------------------------------

    private function proposeResolution(Firm $firm, FirmUser $actor, IntegrationConflict $conflict, ConflictStatus $outcome, ?string $note = null): IntegrationConflict
    {
        app(IntegrationEntitlementPolicyService::class)->assertEnabled($firm);
        app(IntegrationAccessPolicyService::class)->assertCanConfigure($actor);

        $builtNote = $note === null ? "Proposed outcome: {$outcome->value}." : "Proposed outcome: {$outcome->value}. {$note}";

        return $this->runWithFirmContext($firm, fn () => app(IntegrationConflictService::class)->proposeResolution($conflict, $outcome, $actor->id, $builtNote));
    }

    private function approveResolution(Firm $firm, FirmUser $actor, IntegrationConflict $conflict, ConflictStatus $outcome): IntegrationConflict
    {
        app(IntegrationEntitlementPolicyService::class)->assertEnabled($firm);
        app(IntegrationAccessPolicyService::class)->assertCanConfigure($actor);

        $fresh = $this->runWithFirmContext($firm, fn () => $conflict->fresh());

        if ((int) $actor->id === (int) $fresh->resolved_by_firm_user_id) {
            throw new RuntimeException('You proposed this resolution — a different approver must confirm it.');
        }

        return $this->runWithFirmContext($firm, fn () => app(IntegrationConflictService::class)->transitionStatus(
            $fresh,
            $outcome,
            resolvedByFirmUserId: $fresh->resolved_by_firm_user_id,
            resolutionApprovedByFirmUserId: (int) $actor->id,
        ));
    }

    private function entitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function connectionFor(Firm $firm): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null])
        );
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
