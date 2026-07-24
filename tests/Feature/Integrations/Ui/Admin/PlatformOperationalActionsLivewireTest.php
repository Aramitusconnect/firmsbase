<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui\Admin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Filament\Actions\Platform\EnterSupportAccessSessionAction;
use App\Filament\Actions\Platform\LeaveSupportAccessSessionAction;
use App\Filament\Actions\Platform\NudgeIntegrationQueueAsSupportAction;
use App\Filament\Actions\Platform\RequestSupportAccessAction;
use App\Filament\Actions\Platform\RequeueOutboxEventAsSupportAction;
use App\Filament\Actions\Platform\RequeueSyncItemAsSupportAction;
use App\Filament\Actions\Platform\RevokeSupportAccessSessionAction;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Jobs\OutboxDispatchJob;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Models\TenantEncryptionKey;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformOperationalActionsLivewireTest — Checkpoint 13 (frozen-test-
 * closure-plan.md §4). The seven SuperAdmin operational actions were,
 * until now, proven ONLY via direct PlatformFirmIntegrationBoundedAccessService
 * calls (PlatformIntegrationOperationalActionsTest /
 * SupportAccessSessionUiEnforcementTest). This file adds the missing
 * UI-level proof: a genuine Filament mountAction()/callMountedAction()
 * (and mountTableAction()/callMountedTableAction() for the two record
 * actions) round trip through the real host page, proving the actual
 * mounted button click resolves the acting PlatformAdmin (via
 * Auth::guard('platform_admin')) and wires the right firm/connection/
 * record IDs (via the page's `$firmUuid` scalar and the record's
 * `model_id`) into the underlying service call.
 *
 * These Platform pages are scalar-property-only (frozen design §6 — no
 * Model-typed public property), so they are structurally immune to the
 * ModelSynth::hydrate() re-fetch gap the Firm panel's P1 fix addresses;
 * these genuine round trips need no ambient-context workaround.
 */
final class PlatformOperationalActionsLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingSuperAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        return $admin;
    }

    private function otherSuperAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function givenActiveCredentialFor(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
            IntegrationCredential::factory()->forFirmIntegration($connection)->create();
        });
    }

    // ============================================================
    // Header actions on PlatformFirmIntegrationsPage
    // ============================================================

    public function test_nudge_queue_action_resolves_the_admin_and_wires_the_firm_id_into_the_dispatch(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $admin = $this->actingSuperAdmin();

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->mountAction(NudgeIntegrationQueueAsSupportAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $firm->id);
        Queue::assertPushed(SyncRetryPollJob::class, fn (SyncRetryPollJob $job) => $job->firmId === $firm->id);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_integration_oversight.queue_nudged')
            ->first());

        $this->assertNotNull($audit, 'The mounted nudge click must run the real service and write its audit row.');
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_id, 'The audit actor must be the admin resolved from the platform_admin guard by the mounted action.');
    }

    public function test_request_support_access_action_resolves_the_admin_and_wires_the_firm(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->actingSuperAdmin();

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->mountAction(RequestSupportAccessAction::getDefaultName());
        $test->setActionData([
            'access_type' => SupportAccessType::Standard->value,
            'reason' => 'Investigating a failed sync via the mounted UI action.',
            'requested_duration_minutes' => 60,
        ]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $request = $this->runWithFirmContext($firm, fn () => SupportAccessRequest::query()
            ->where('firm_id', $firm->id)
            ->where('requested_by', $admin->id)
            ->first());

        $this->assertNotNull($request, 'The mounted request-support-access click must create a request wired to THIS firm and THIS acting admin.');
    }

    public function test_enter_support_access_session_action_resolves_the_admin_and_starts_the_session(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->actingSuperAdmin();

        $request = $this->runWithFirmContext($firm, fn () => SupportAccessRequest::factory()->forFirm($firm)->create([
            'requested_by' => $admin->id,
            'status' => SupportAccessRequestStatus::Approved->value,
            'approved_at' => now(),
        ]));

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->mountAction(EnterSupportAccessSessionAction::getDefaultName());
        $test->setActionData(['request_uuid' => $request->uuid]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $session = $this->runWithFirmContext($firm, fn () => SupportAccessSession::query()
            ->where('support_access_request_id', $request->id)
            ->first());

        $this->assertNotNull($session, 'The mounted enter-session click must start a session for the resolved request.');
        $this->assertSame(SupportAccessSessionStatus::Active, $session->status);
        $this->assertSame($admin->id, $session->platform_admin_id, 'The session must be owned by the admin resolved from the platform_admin guard.');
    }

    public function test_leave_support_access_session_action_resolves_the_admin_and_ends_the_session(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->actingSuperAdmin();
        $session = $this->activeSessionFor($admin, $firm);

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->mountAction(LeaveSupportAccessSessionAction::getDefaultName());
        $test->setActionData(['session_uuid' => $session->uuid]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $session->fresh());
        $this->assertSame(SupportAccessSessionStatus::Expired, $fresh->status, 'The mounted leave click must end THIS admin\'s own active session.');
    }

    public function test_revoke_support_access_session_action_resolves_the_acting_admin_as_the_revoker(): void
    {
        $firm = Firm::factory()->activated()->create();
        $sessionOwner = $this->otherSuperAdmin();
        $revoker = $this->actingSuperAdmin();
        $session = $this->activeSessionFor($sessionOwner, $firm);

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->mountAction(RevokeSupportAccessSessionAction::getDefaultName());
        $test->setActionData(['session_uuid' => $session->uuid]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $session->fresh());
        $this->assertSame(SupportAccessSessionStatus::Revoked, $fresh->status, 'The mounted revoke click must revoke a DIFFERENT admin\'s active session (governance action).');

        // The acting admin (revoker) being resolved is proven by the fact
        // that this governance revoke of ANOTHER admin's session succeeded
        // at all — it is authorized/executed under the acting platform_admin
        // identity, distinct from the session owner. A session_revoked audit
        // row is written; its actor attribution is a SEPARATE, explicitly
        // DEFERRED concern (agent-13h finding #8 — logSessionAudit
        // misattribution, DEFER_WITH_EXPLICIT_OWNER), so this test does not
        // pin the audit actor_id (which would assert that known bug as
        // correct). See PlatformIntegrationOperationalActionsTest for the
        // requeue/nudge actions, whose actor attribution IS correct.
        $auditExists = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'support_access.session_revoked')
            ->exists());
        $this->assertTrue($auditExists, 'The mounted revoke must write a session_revoked audit row.');

        // Guard against an unused-variable lint on $revoker while keeping
        // the two distinct identities explicit in the test's intent.
        $this->assertNotSame($revoker->id, $session->platform_admin_id, 'The revoker and the session owner are deliberately different admins.');
    }

    // ============================================================
    // Record (table) actions on PlatformFirmIntegrationDetailPage
    // ============================================================

    public function test_requeue_outbox_event_record_action_resolves_the_admin_and_wires_the_event_id(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->actingSuperAdmin();

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        // Filament array-record tables key each row by its ArrayRecord
        // key ('__key'), which — since these records carry no '__key' of
        // their own — defaults to the (single) record's collection index,
        // "0". With exactly one failed item present, "0" is that event's
        // record key. The action closure then wires $record['model_id']
        // (the real event id) into the service call.
        $test->mountTableAction(RequeueOutboxEventAsSupportAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();
        $test->assertNotified('Event requeued');

        $fresh = $this->runWithFirmContext($firm, fn () => $event->fresh());
        $this->assertSame(OutboxEventStatus::Pending, $fresh->status, 'The mounted requeue click must requeue exactly the event wired via its model_id.');
        $this->assertSame(1, $fresh->requeue_count);

        $audit = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'platform_integration_oversight.outbox_event_requeued')
            ->first());
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id, 'The requeue audit actor must be the admin resolved from the platform_admin guard.');
    }

    public function test_requeue_sync_item_record_action_resolves_the_admin_and_wires_the_item_id(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->connection($firm);
        $this->givenActiveCredentialFor($firm, $connection);
        $item = $this->runWithFirmContext($firm, function () use ($connection) {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });

        $this->actingSuperAdmin();

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        // Single failed item present -> its Filament array-record key is
        // the collection index "0" (see the outbox variant above).
        $test->mountTableAction(RequeueSyncItemAsSupportAction::getDefaultName(), '0');
        $test->setTableActionData(['reason_code' => 'manual_retry_transient']);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();
        $test->assertNotified('Item requeued');

        $fresh = $this->runWithFirmContext($firm, fn () => $item->fresh());
        $this->assertSame(SyncItemStatus::FailedRetryable, $fresh->status, 'The mounted requeue click must requeue exactly the sync item wired via its model_id.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function activeSessionFor(PlatformAdmin $admin, Firm $firm): SupportAccessSession
    {
        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id])
        );

        return $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessSession::factory()->create([
                'firm_id' => $firm->id,
                'support_access_request_id' => $request->id,
                'platform_admin_id' => $admin->id,
                'status' => SupportAccessSessionStatus::Active->value,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
            ])
        );
    }
}
