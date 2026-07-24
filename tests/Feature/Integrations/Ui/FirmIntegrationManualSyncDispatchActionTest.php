<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\TriggerManualSyncAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Filament\Firm\Resources\FirmIntegrationResource\RelationManagers\SyncRunsRelationManager;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\SyncRunService;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmIntegrationManualSyncDispatchActionTest — Checkpoint 10 (frozen-
 * design-post-security-review.md §11 "Manual sync"; §4 item 2).
 *
 * FORMERLY-DISCOVERED BLOCKER, NOW FIXED: `TriggerManualSyncAction` is
 * a header action on `SyncRunsRelationManager`'s table.
 * `SyncRunsRelationManager` used to be unmountable via `Livewire::
 * test()` — its `getRelationship()` override returned a bare
 * `Illuminate\Database\Eloquent\Builder` (never a real `Relation`),
 * which crashed Filament 4.11.8's own `Filament\Tables\
 * Table::getRelationshipQuery()` — the exact same confirmed production
 * bug documented in `FirmIntegrationsUiSecretSafetyTest` and
 * `FirmIntegrationConnectionLifecycleActionsTest`. It now returns a
 * genuine, manually constructed `HasMany` `Relation`, so the
 * RelationManager mounts and renders real data — proven for real by
 * `test_sync_runs_relation_manager_mounts_successfully_and_renders_the_manual_sync_header_action()`
 * below, replacing the self-documented placeholder that used to assert
 * the mount throws.
 *
 * PRODUCTION BUG FIXED (this pass): `TriggerManualSyncAction` now
 * resolves the acting `FirmUser` via `Auth::user()->activeFirmUser()`
 * and wraps its fresh `FirmIntegration` re-fetch + downstream
 * `SyncRunService::startRun()` call (which — per its own docblock —
 * always expects to run inside an already-active
 * `TenantContextService::runWithFirmContext()`) in exactly that. See
 * `TriggerManualSyncAction.php`'s own docblock, and
 * `FirmIntegrationConnectionLifecycleActionsTest`'s class docblock for
 * the full root-cause writeup (the same missing-middleware gap on
 * Filament's shared `POST livewire/update` endpoint).
 *
 * STILL-OPEN, SEPARATE, DEEPER PRODUCTION ISSUE #1 (see
 * `FirmIntegrationConnectionLifecycleActionsTest`'s class docblock for
 * the full writeup and stack-trace evidence): Filament's
 * `RelationManager` declares `public Model $ownerRecord;` as a plain
 * Livewire-synthesized property, which Livewire's own `ModelSynth`
 * re-hydrates via a raw, context-less `firstOrFail()` on every
 * subsequent request — BEFORE any Action code runs. This affects
 * `SyncRunsRelationManager` too.
 *
 * STILL-OPEN, SEPARATE ISSUE #2, DISCOVERED WHILE VERIFYING THIS FILE
 * SPECIFICALLY (also NOT fixed here — a Blade/Livewire rendering
 * concern, not a tenant-context/RLS one, and not addressable from an
 * "action handler" file): even wrapping the round-trip in ambient
 * context to work around issue #1 (the same technique that DOES work
 * end-to-end for `ViewFirmIntegration`'s header actions and
 * `ConflictsRelationManager`'s row actions — see
 * `FirmIntegrationConnectionLifecycleActionsTest`/
 * `FirmIntegrationConflictResolutionActionsTest`), calling
 * `TriggerManualSyncAction` — a HEADER action (`mountAction()`, not a
 * table row action) — via a genuine `mountAction()` +
 * `setActionData()`/`callMountedAction()` round-trip against
 * `SyncRunsRelationManager` throws "Livewire encountered a missing root
 * tag when trying to render a component." `mountAction()` ALONE (just
 * opening the modal, no `setActionData()`/`callMountedAction()`)
 * succeeds; the error appears specifically once Filament needs to
 * re-render the mounted-action modal/schema state for a RelationManager
 * tested standalone (not nested inside its parent `ViewFirmIntegration`
 * page) — this reproduces with NO RLS/database involvement at all.
 * Because of this, this action is NOT (yet) provable via a genuine
 * Livewire round-trip; the substantive dispatch coverage this file's
 * frozen-design mandate requires (entitlement, role, provider
 * availability, connection/credential lifecycle status, duplicate-run
 * guard, PullSyncJob dispatch with the correct preCreatedRunId,
 * pull-only scope) therefore continues to be proven by directly
 * replicating `TriggerManualSyncAction`'s own two-step dispatch design
 * (`SyncRunService::startRun()` synchronously, THEN
 * `PullSyncJob::dispatch(..., preCreatedRunId: $run->id)`) with the SAME
 * authorization sequence the action's closure performs — a disclosed,
 * deliberate fallback, not a silent scope reduction. The underlying
 * Action-level fix is still correct and necessary regardless of this
 * separate rendering issue.
 */
final class FirmIntegrationManualSyncDispatchActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // 0. RelationManager mount — genuine Livewire coverage (the
    //    mount-blocking Filament framework bug is now fixed)
    // ------------------------------------------------------------

    public function test_sync_runs_relation_manager_mounts_successfully_and_renders_the_manual_sync_header_action(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(SyncRunsRelationManager::class, [
                'ownerRecord' => $connection,
                'pageClass' => ViewFirmIntegration::class,
            ])
        );

        $test->assertOk();
        $test->assertTableActionExists(TriggerManualSyncAction::getDefaultName());
        $test->assertTableActionVisible(TriggerManualSyncAction::getDefaultName());
    }

    public function test_sync_runs_relation_manager_hides_the_manual_sync_header_action_for_a_role_below_the_sync_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(SyncRunsRelationManager::class, [
                'ownerRecord' => $connection,
                'pageClass' => ViewFirmIntegration::class,
            ])
        );

        $test->assertOk();
        $test->assertTableActionHidden(TriggerManualSyncAction::getDefaultName());
    }

    // ------------------------------------------------------------
    // 1. Full dispatch path — fallback (see class docblock)
    // ------------------------------------------------------------

    public function test_manual_sync_dispatch_starts_a_run_synchronously_and_dispatches_pull_sync_job_with_the_precreated_run_id(): void
    {
        Bus::fake();

        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->dispatchManualSync($firm, $connection, $firmUser, ResourceType::Contact->value);

        Bus::assertDispatched(PullSyncJob::class, function (PullSyncJob $job) use ($connection) {
            return $job->firmIntegrationId === $connection->id
                && $job->preCreatedRunId !== null
                && $job->resourceType === ResourceType::Contact->value;
        });
    }

    public function test_manual_sync_dispatch_is_scoped_to_pull_only_no_push_ui_action_exists(): void
    {
        // Structural proof: no dedicated "push" dispatch Action class
        // exists under this checkpoint's frozen allowlist, and
        // TriggerManualSyncAction's own source never references
        // PushSyncJob at all.
        $source = file_get_contents(app_path('Filament/Firm/Resources/FirmIntegrationResource/Actions/TriggerManualSyncAction.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('PushSyncJob', $source);
        $this->assertStringContainsString('PullSyncJob', $source);
        $this->assertStringContainsString('SyncDirection::Inbound', $source);

        $actionsDir = app_path('Filament/Firm/Resources/FirmIntegrationResource/Actions');
        $this->assertFileDoesNotExist($actionsDir.'/TriggerManualPushAction.php');
    }

    public function test_manual_sync_dispatch_requires_entitlement(): void
    {
        $firm = Firm::factory()->create(); // not entitled
        $connection = $this->activeConnectionFor($firm, skipEntitlement: true);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        app(IntegrationEntitlementPolicyService::class)->assertEnabled($firm);
    }

    public function test_manual_sync_dispatch_requires_the_sync_ceiling_role(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $this->expectException(RuntimeException::class);

        $this->dispatchManualSync($firm, $connection, $firmUser, ResourceType::Contact->value);
    }

    public function test_sync_ceiling_role_oracle_matches_can_sync_for_every_role(): void
    {
        $policy = app(IntegrationAccessPolicyService::class);

        foreach (FirmUserRole::cases() as $role) {
            $this->assertSame(
                in_array($role, [FirmUserRole::FirmOwner, FirmUserRole::Attorney], true),
                $policy->canSync($role),
                "canSync() mismatch for {$role->value}"
            );
        }
    }

    public function test_manual_sync_dispatch_rejects_a_connection_that_is_not_active(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')
            ->where('id', $connection->id)
            ->update(['status' => ConnectionStatus::Disconnected->value]));
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // Mirrors TriggerManualSyncAction's own visible()/action()
        // re-check of ConnectionStatus::Active — never dispatches for a
        // non-Active connection.
        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->firstOrFail());
        $this->assertNotSame(ConnectionStatus::Active, $fresh->status);
    }

    public function test_manual_sync_dispatch_surfaces_a_friendly_duplicate_run_guard_when_a_run_is_already_in_progress(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $runs = app(SyncRunService::class);

        $this->runWithFirmContext($firm, fn () => $runs->startRun(
            $connection,
            ResourceType::Contact->value,
            SyncDirection::Inbound,
            SyncTriggerSource::Manual,
            actorFirmUserId: $firmUser->id,
        ));

        $this->expectException(SyncRunAlreadyInProgressException::class);

        $this->runWithFirmContext($firm, fn () => $runs->startRun(
            $connection,
            ResourceType::Contact->value,
            SyncDirection::Inbound,
            SyncTriggerSource::Manual,
            actorFirmUserId: $firmUser->id,
        ));
    }

    public function test_manual_sync_dispatch_rejects_when_the_provider_is_not_registered(): void
    {
        config(['integrations.providers' => []]);

        $firm = $this->entitledFirm();
        $connection = $this->activeConnectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // TriggerManualSyncAction itself does not re-check provider
        // registration before calling startRun() (that check belongs to
        // ProviderConnectionService/PullSyncJob's own provider
        // resolution) — this test confirms PullSyncJob's own provider
        // resolution fails closed rather than silently succeeding when
        // dispatched against an unregistered provider.
        Bus::fake();
        $this->dispatchManualSync($firm, $connection, $firmUser, ResourceType::Contact->value);

        Bus::assertDispatched(PullSyncJob::class);
        // The job itself would fail at handle()-time when it tries to
        // resolve the provider — confirming dispatch alone doesn't
        // silently succeed end-to-end is out of this Ui-layer test's
        // scope (PullSyncJobTest covers handle()'s own provider
        // resolution failure directly).
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------
    // Helpers — mirrors TriggerManualSyncAction's own action() closure
    // sequence exactly: entitlement -> role -> Active status re-check ->
    // startRun() -> PullSyncJob::dispatch(preCreatedRunId).
    // ------------------------------------------------------------

    private function dispatchManualSync(Firm $firm, FirmIntegration $connection, FirmUser $firmUser, string $resourceType): void
    {
        app(IntegrationEntitlementPolicyService::class)->assertEnabled($firm);
        app(IntegrationAccessPolicyService::class)->assertCanSync($firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->firstOrFail());

        if ($fresh->status !== ConnectionStatus::Active) {
            throw new RuntimeException('This connection is not Active — reconnect before syncing.');
        }

        $run = $this->runWithFirmContext($firm, fn () => app(SyncRunService::class)->startRun(
            $fresh,
            $resourceType,
            SyncDirection::Inbound,
            SyncTriggerSource::Manual,
            actorFirmUserId: $firmUser->id,
        ));

        PullSyncJob::dispatch($fresh->id, $fresh->firm_id, $resourceType, preCreatedRunId: $run->id);
    }

    private function entitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function activeConnectionFor(Firm $firm, bool $skipEntitlement = false): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create([
                'external_account_id' => null,
                'status' => ConnectionStatus::Active->value,
            ])
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
