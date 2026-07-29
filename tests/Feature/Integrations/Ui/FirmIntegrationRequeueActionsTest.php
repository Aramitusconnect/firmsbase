<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\RequeueOutboxEventAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\RequeueSyncItemAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\FailedItemsRelationManager;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\RequeueIneligibilityReason;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\SyncItemService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmIntegrationRequeueActionsTest — Checkpoint 10 (frozen-design-
 * post-security-review.md §5, §11).
 *
 * FORMERLY-DISCOVERED BLOCKER, NOW FIXED:
 * `RequeueOutboxEventAction`/`RequeueSyncItemAction` are row actions on
 * `FailedItemsRelationManager`. That RelationManager used to be
 * unmountable via `Livewire::test()` either — `Filament\Resources\
 * RelationManagers\RelationManager::canViewForRecord()` (invoked
 * automatically by the `CanAuthorizeAccess` trait's
 * `hydrateCanAuthorizeAccess()` Livewire hook on every mount) called
 * `$ownerRecord->{'failedItems'}()` directly on `FirmIntegration`,
 * which has no such Eloquent relationship method (by design — see that
 * class's own docblock) — the SAME confirmed root-cause bug documented
 * in the other Ui test files in this checkpoint. It now overrides
 * `canViewForRecord()` directly instead, so the RelationManager mounts
 * and renders real combined outbox-event/sync-item rows — proven for
 * real by
 * `test_failed_items_relation_manager_mounts_successfully_and_renders_both_requeue_row_actions()`
 * below, replacing the self-documented placeholder that used to assert
 * the mount throws.
 *
 * PRODUCTION BUG FIXED (this pass): `RequeueOutboxEventAction`/
 * `RequeueSyncItemAction` now resolve the acting `FirmUser` via
 * `Auth::user()->activeFirmUser()` and wrap
 * `IntegrationOutboxEventService::requeue()`/
 * `SyncItemService::requeueFromFailedPermanent()` (and the subsequent
 * `diagnoseRequeueIneligibility()` call on a null result) in
 * `TenantContextService::runWithFirmContext($firmId, ...)` — see both
 * Action files' own docblocks, and
 * `FirmIntegrationConnectionLifecycleActionsTest`'s class docblock for
 * the full root-cause writeup (the same missing-middleware gap on
 * Filament's shared `POST livewire/update` endpoint). Note this
 * particular pair's raw guarded-UPDATE SQL already carries an explicit
 * `WHERE ... firm_id = ?` predicate — FORCE RLS still silently excluded
 * every row regardless of that explicit predicate when no
 * `app.current_firm_id` session setting was active, so the bug
 * manifested here as `requeue()`/`requeueFromFailedPermanent()`
 * returning null (a false "could not requeue" rejection) rather than a
 * thrown `ModelNotFoundException` — a DIFFERENT symptom from
 * `ViewFirmIntegration`'s, same root cause.
 *
 * STILL-OPEN, SEPARATE, DEEPER PRODUCTION ISSUE #1 (see
 * `FirmIntegrationConnectionLifecycleActionsTest`'s class docblock for
 * the full writeup and stack-trace evidence): Filament's
 * `RelationManager` declares `public Model $ownerRecord;` as a plain
 * Livewire-synthesized property, which Livewire's own `ModelSynth`
 * re-hydrates via a raw, context-less `firstOrFail()` on every
 * subsequent request — BEFORE any Action code runs. Confirmed
 * empirically for `FailedItemsRelationManager` too (wrapping a genuine
 * `mountTableAction()`/`callMountedTableAction()` round-trip in
 * `$this->runWithFirmContext($firm, ...)`, exactly as
 * `FirmIntegrationConnectionLifecycleActionsTest`/
 * `FirmIntegrationConflictResolutionActionsTest` do, is necessary here
 * too before the fixed Action's own re-fetch is even reached).
 *
 * STILL-OPEN, SEPARATE ISSUE #2, DISCOVERED WHILE VERIFYING THIS FILE
 * SPECIFICALLY (also NOT fixed here — a Blade/Livewire rendering
 * concern, not a tenant-context/RLS one, and not addressable from an
 * "action handler" file): even WITH issue #1 worked around via the
 * ambient wrap, a genuine `mountTableAction()` +
 * `setActionData()`/`callMountedTableAction()` round-trip against
 * `FailedItemsRelationManager` specifically (unlike
 * `ConflictsRelationManager`, which mounts/dispatches its row actions
 * fine under the same wrap — see
 * `FirmIntegrationConflictResolutionActionsTest`) throws "Livewire
 * encountered a missing root tag when trying to render a component."
 * This reproduces with NO RLS/database involvement at all (confirmed by
 * probing `mountTableAction()` alone, without ever calling
 * `setActionData()`/`callMountedTableAction()` — no error; the error
 * appears specifically once Filament needs to re-render this
 * RelationManager's Blade output for the array-record
 * (`Table::records()`-backed, non-Eloquent-relationship) table this
 * class deliberately uses — see this class's own top docblock).
 * Because of this, this file's requeue actions are NOT (yet) provable
 * via a genuine Livewire round-trip the way the other five affected
 * actions in this checkpoint now are; `diagnoseRequeueIneligibility()`'s
 * gating discipline and the combined-view's dual-type row shape remain
 * proven below via the pre-existing disclosed-fallback tests, unchanged
 * — the underlying Action-level fix (see `RequeueOutboxEventAction.php`/
 * `RequeueSyncItemAction.php`) is still correct and necessary
 * regardless of this separate rendering issue.
 */
final class FirmIntegrationRequeueActionsTest extends TestCase
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

    public function test_failed_items_relation_manager_mounts_successfully_and_renders_both_requeue_row_actions(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(FailedItemsRelationManager::class, [
                'ownerRecord' => $connection,
                'pageClass' => ViewFirmIntegration::class,
            ])
        );

        $test->assertOk();
        $test->assertSee($event->event_type);
        $test->assertSee($item->resource_type);
        $test->assertTableActionExists(RequeueOutboxEventAction::getDefaultName());
        $test->assertTableActionExists(RequeueSyncItemAction::getDefaultName());
    }

    // ------------------------------------------------------------
    // 1. RequeueOutboxEventAction — fallback (see class docblock)
    // ------------------------------------------------------------

    public function test_requeue_outbox_event_action_succeeds_for_an_eligible_dead_lettered_event(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);

        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $result = $this->requeueOutboxEvent($firm, $firmUser, $event->id);

        $this->assertNotNull($result);
        $this->assertSame(OutboxEventStatus::Pending, $result->status);
    }

    public function test_requeue_outbox_event_action_is_denied_below_the_configure_ceiling(): void
    {
        // Durable Firm required: requeueOutboxEvent()'s assertCanConfigure()
        // denial writes integration_governance.action_denied on the
        // independent 'pgsql_audit' connection (IntegrationAccessPolicyService::
        // recordDenied() -> TimelineEventRecorder::recordOnIndependentConnection()),
        // which cannot see a Firm still uncommitted inside this test's
        // RefreshDatabase transaction — same shape as
        // IntegrationAuditEventTypeTest::test_governance_action_denied_fires_on_a_policy_denial().
        // Found and fixed during FirmsVault Live Integrations Checkpoint
        // 4's post-commit full-suite verification (pre-existing since
        // Checkpoint 9's own introduction of the durable-write pattern).
        $firm = $this->entitledDurableFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::LegalAssistant);

        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $this->expectException(RuntimeException::class);

        $this->requeueOutboxEvent($firm, $firmUser, $event->id);
    }

    public function test_requeue_outbox_event_action_diagnoses_the_correct_reason_for_every_rejection_case_and_never_gates_on_it(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // Wrong status (never dead_lettered) -> NotEligibleStatus.
        $pendingEvent = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->create());

        $service = app(IntegrationOutboxEventService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeue($pendingEvent->id, $firm->id, 'manual_retry', $firmUser->id));

        $this->assertNull($result, 'requeue() itself must be the ONLY thing that gates eligibility — the diagnostic below is read-only, consulted only after this null.');

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($pendingEvent->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::NotEligibleStatus, $reason);
    }

    public function test_requeue_outbox_event_diagnoses_ceiling_reached_when_requeue_count_hits_max_requeues(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);

        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->deadLettered()->create(['requeue_count' => 3, 'max_requeues' => 3]));

        $service = app(IntegrationOutboxEventService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeue($event->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($event->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::RequeueCeilingReached, $reason);
    }

    public function test_requeue_outbox_event_diagnoses_connection_disconnected(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::Disconnected->value]));

        $service = app(IntegrationOutboxEventService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeue($event->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($event->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::ConnectionDisconnected, $reason);
    }

    public function test_requeue_outbox_event_diagnoses_credential_revoked(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $credential = $this->createWithFirmContext($firm, fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->create());
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('id', $credential->id)->update(['status' => 'revoked']));

        $service = app(IntegrationOutboxEventService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeue($event->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($event->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::CredentialRevoked, $reason);
    }

    public function test_requeue_outbox_event_diagnoses_not_found_or_cross_firm(): void
    {
        $firmA = $this->entitledFirm();
        $firmB = $this->entitledFirm();
        $connectionB = $this->activeConnectionWithCredential($firmB);
        $eventB = $this->createWithFirmContext($firmB, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connectionB)->deadLettered()->create());

        $service = app(IntegrationOutboxEventService::class);
        $reason = $this->runWithFirmContext($firmA, fn () => $service->diagnoseRequeueIneligibility($eventB->id, $firmA->id));

        $this->assertSame(RequeueIneligibilityReason::NotFoundOrCrossFirm, $reason);
    }

    public function test_requeue_outbox_event_diagnoses_superseded(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);

        $old = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->deadLettered()
            ->create(['resource_type' => 'contact', 'resource_id' => '1', 'created_at' => now()->subHour()]));

        $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->completed()
            ->create(['resource_type' => 'contact', 'resource_id' => '1', 'created_at' => now()]));

        $service = app(IntegrationOutboxEventService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeue($old->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($old->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::Superseded, $reason);
    }

    public function test_requeue_outbox_event_diagnostic_returns_null_for_the_still_eligible_race_edge_case(): void
    {
        // A rare race: requeue() itself returned null (e.g. lost a
        // concurrent race), but by the time the diagnostic re-checks,
        // every clause now passes — the diagnostic must return null
        // (rendered as a generic "please try again," never a fabricated
        // specific reason), not throw or invent a reason.
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $service = app(IntegrationOutboxEventService::class);
        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($event->id, $firm->id));

        $this->assertNull($reason, 'A genuinely-eligible event must diagnose as null (no ineligibility reason).');
    }

    // ------------------------------------------------------------
    // 2. RequeueSyncItemAction — fallback (see class docblock)
    // ------------------------------------------------------------

    public function test_requeue_sync_item_action_succeeds_for_an_eligible_failed_permanent_item(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $result = $this->requeueSyncItem($firm, $firmUser, $item->id);

        $this->assertNotNull($result);
        $this->assertSame(SyncItemStatus::FailedRetryable, $result->status);
    }

    public function test_requeue_sync_item_action_is_denied_below_the_configure_ceiling(): void
    {
        // Durable Firm required — see
        // test_requeue_outbox_event_action_is_denied_below_the_configure_ceiling()'s
        // own comment for the full reasoning (requeueSyncItem() reaches
        // the identical assertCanConfigure() denial sink).
        $firm = $this->entitledDurableFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $firmUser = $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->expectException(RuntimeException::class);

        $this->requeueSyncItem($firm, $firmUser, $item->id);
    }

    public function test_requeue_sync_item_diagnoses_not_eligible_status_and_never_gates_on_it(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->create()); // pending

        $service = app(SyncItemService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($item->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::NotEligibleStatus, $reason);
    }

    public function test_requeue_sync_item_diagnostic_never_returns_ceiling_reached_since_no_ceiling_column_exists(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        // Requeue many times in a row — never rejected by a ceiling,
        // and diagnoseRequeueIneligibility() must never surface
        // RequeueCeilingReached for a sync item.
        $service = app(SyncItemService::class);
        for ($i = 0; $i < 5; $i++) {
            $requeued = $this->runWithFirmContext($firm, fn () => $service->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));
            $this->assertNotNull($requeued);
            $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->where('id', $item->id)->update(['status' => SyncItemStatus::FailedPermanent->value]));
        }

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($item->id, $firm->id));
        $this->assertNotSame(RequeueIneligibilityReason::RequeueCeilingReached, $reason);
    }

    public function test_requeue_sync_item_diagnoses_connection_disconnected(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::Disconnected->value]));

        $service = app(SyncItemService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($item->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::ConnectionDisconnected, $reason);
    }

    public function test_requeue_sync_item_diagnoses_credential_revoked(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $credential = $this->createWithFirmContext($firm, fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->create());
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('id', $credential->id)->update(['status' => 'revoked']));

        $service = app(SyncItemService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($item->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::CredentialRevoked, $reason);
    }

    public function test_requeue_sync_item_diagnoses_superseded(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);

        $oldRun = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $oldItem = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($oldRun)->failedPermanent()->create(['external_id' => 'ext-shared']));

        $newerRun = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create(['created_at' => now()->addMinute()]));
        $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($newerRun)->succeeded()->create(['external_id' => 'ext-shared']));

        $service = app(SyncItemService::class);
        $result = $this->runWithFirmContext($firm, fn () => $service->requeueFromFailedPermanent($oldItem->id, $firm->id, 'manual_retry'));
        $this->assertNull($result);

        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($oldItem->id, $firm->id));
        $this->assertSame(RequeueIneligibilityReason::Superseded, $reason);
    }

    public function test_requeue_sync_item_diagnoses_not_found_or_cross_firm(): void
    {
        $firmA = $this->entitledFirm();
        $firmB = $this->entitledFirm();
        $connectionB = $this->activeConnectionWithCredential($firmB);
        $runB = $this->createWithFirmContext($firmB, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connectionB)->create());
        $itemB = $this->createWithFirmContext($firmB, fn () => IntegrationSyncItem::factory()->forSyncRun($runB)->failedPermanent()->create());

        $service = app(SyncItemService::class);
        $reason = $this->runWithFirmContext($firmA, fn () => $service->diagnoseRequeueIneligibility($itemB->id, $firmA->id));

        $this->assertSame(RequeueIneligibilityReason::NotFoundOrCrossFirm, $reason);
    }

    public function test_requeue_sync_item_diagnostic_returns_null_for_the_still_eligible_race_edge_case(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionWithCredential($firm);
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $service = app(SyncItemService::class);
        $reason = $this->runWithFirmContext($firm, fn () => $service->diagnoseRequeueIneligibility($item->id, $firm->id));

        $this->assertNull($reason);
    }

    // ------------------------------------------------------------
    // 3. Reason description() strings (rendered verbatim in the
    //    Filament notification body per both Action classes)
    // ------------------------------------------------------------

    public function test_every_requeue_ineligibility_reason_has_a_non_empty_human_readable_description(): void
    {
        foreach (RequeueIneligibilityReason::cases() as $reason) {
            $this->assertNotEmpty($reason->description());
        }
    }

    // ------------------------------------------------------------
    // Helpers — replicate RequeueOutboxEventAction's/
    // RequeueSyncItemAction's own action() closure sequence exactly.
    // ------------------------------------------------------------

    private function requeueOutboxEvent(Firm $firm, FirmUser $firmUser, int $eventId): ?IntegrationOutboxEvent
    {
        app(IntegrationEntitlementPolicyService::class)->assertEnabled($firm);
        app(IntegrationAccessPolicyService::class)->assertCanConfigure($firmUser);

        return $this->runWithFirmContext(
            $firm,
            fn () => app(IntegrationOutboxEventService::class)->requeue($eventId, $firm->id, 'manual_retry_after_provider_fix', $firmUser->id)
        );
    }

    private function requeueSyncItem(Firm $firm, FirmUser $firmUser, int $itemId): ?IntegrationSyncItem
    {
        app(IntegrationEntitlementPolicyService::class)->assertEnabled($firm);
        app(IntegrationAccessPolicyService::class)->assertCanConfigure($firmUser);

        return $this->runWithFirmContext(
            $firm,
            fn () => app(SyncItemService::class)->requeueFromFailedPermanent($itemId, $firm->id, 'manual_retry_after_provider_fix', $firmUser->id)
        );
    }

    /**
     * Same shape as entitledFirm(), except the Firm is created via
     * Firm::factory()->connection('pgsql_audit')->create() — a real,
     * immediate commit visible to the separate 'pgsql_audit' session
     * TimelineEventRecorder::recordOnIndependentConnection() uses for
     * assertCanConfigure()'s denial-path audit write. Required only by
     * the two authorization-denial tests above; every other test using
     * entitledFirm() never reaches that code path.
     */
    private function entitledDurableFirm(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    /**
     * Mirrors IntegrationAuditEventTypeTest::cleanUpDurableFirmAuditTrailAfterRollback()
     * exactly (see that method's own docblock for the full deadlock
     * reasoning) — registered via beforeApplicationDestroyed() so it
     * runs after RefreshDatabase's own rollback has already released
     * the FOR KEY SHARE lock the default-connection fixtures hold on
     * this Firm row.
     */
    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
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

    private function activeConnectionWithCredential(Firm $firm): FirmIntegration
    {
        $connection = $this->connectionFor($firm);
        $this->createWithFirmContext($firm, fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->create());

        return $connection;
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
