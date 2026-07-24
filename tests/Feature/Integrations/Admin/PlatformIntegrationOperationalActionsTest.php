<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Jobs\OutboxDispatchJob;
use App\Jobs\RetentionSweepJob;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TenantEncryptionKey;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PlatformIntegrationOperationalActionsTest — Checkpoint 11 (frozen-
 * design-post-security-review.md §7, §4). Proves the SuperAdmin
 * operational actions (requeue outbox event / requeue sync item /
 * nudge queue / retention sweep dry-run preview) correctly REACH and
 * RESPECT the existing, unmodified service-level guard clauses (proven
 * at the service level already by Checkpoints 9/10's own tests — not
 * re-derived here), are idempotent, dispatch the correct jobs, and
 * always write a companion `security_events` audit row with correct
 * `reason_code`/`actor_type`/`actor_id`.
 */
final class PlatformIntegrationOperationalActionsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Requeue outbox event
    // ------------------------------------------------------------

    public function test_an_eligible_dead_lettered_outbox_event_is_successfully_requeued(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $result = $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $this->assertNotNull($result);
        $this->assertSame(\App\Integrations\Enums\OutboxEventStatus::Pending, $result->status);
        $this->assertSame(1, $result->requeue_count);
    }

    public function test_requeue_of_an_event_belonging_to_a_different_firm_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $otherConnection = $this->runWithFirmContext($otherFirm, fn () => FirmIntegration::factory()->forFirm($otherFirm)->create());
        $otherEvent = $this->runWithFirmContext($otherFirm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($otherConnection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        // Deliberately pass $firm (NOT $otherFirm) with $otherEvent's id
        // — the guard's firm_id check must reject this cross-firm
        // combination.
        $result = $bounded->requeueOutboxEvent($admin, $firm, $otherEvent->id, 'manual_retry_transient');

        $this->assertNull($result, 'A cross-firm requeue attempt must be rejected by the existing guarded UPDATE.');
    }

    public function test_requeue_of_an_event_not_in_dead_lettered_status_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->create()); // pending, not dead-lettered

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $result = $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $this->assertNull($result);
    }

    public function test_requeue_of_an_event_on_a_disconnected_connection_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->disconnected()->create());
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $result = $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $this->assertNull($result, 'A requeue attempt against a disconnected connection must be rejected by the existing guard.');
    }

    public function test_a_duplicate_requeue_attempt_is_idempotent(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $first = $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');
        $second = $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second requeue call on an already-requeued (now pending, not dead_lettered) event must be rejected by the guard, not double-requeue.');

        $fresh = $this->runWithFirmContext($firm, fn () => $event->fresh());
        $this->assertSame(1, $fresh->requeue_count, 'Exactly one real requeue must have taken effect.');
    }

    // ------------------------------------------------------------
    // Requeue sync item
    // ------------------------------------------------------------

    public function test_an_eligible_failed_permanent_sync_item_is_successfully_requeued(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->givenActiveCredentialFor($firm, $connection);
        $item = $this->runWithFirmContext($firm, function () use ($connection) {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $result = $bounded->requeueSyncItem($admin, $firm, $item->id, 'manual_retry_transient');

        $this->assertNotNull($result);
        $this->assertSame(\App\Integrations\Enums\SyncItemStatus::FailedRetryable, $result->status);
    }

    public function test_requeue_sync_item_wrong_status_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $item = $this->runWithFirmContext($firm, function () use ($connection) {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->succeeded()->create(); // not failed_permanent
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $result = $bounded->requeueSyncItem($admin, $firm, $item->id, 'manual_retry_transient');

        $this->assertNull($result);
    }

    public function test_requeue_sync_item_cross_firm_is_denied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $otherFirm = Firm::factory()->activated()->create();
        $otherConnection = $this->runWithFirmContext($otherFirm, fn () => FirmIntegration::factory()->forFirm($otherFirm)->create());
        $otherItem = $this->runWithFirmContext($otherFirm, function () use ($otherConnection) {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($otherConnection)->succeeded()->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $result = $bounded->requeueSyncItem($admin, $firm, $otherItem->id, 'manual_retry_transient');

        $this->assertNull($result);
    }

    // ------------------------------------------------------------
    // Queue nudge
    // ------------------------------------------------------------

    public function test_nudge_queue_dispatches_the_outbox_and_sync_retry_jobs_for_the_correct_firm(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->nudgeQueue($admin, $firm);

        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $firm->id);
        Queue::assertPushed(SyncRetryPollJob::class, fn (SyncRetryPollJob $job) => $job->firmId === $firm->id);
    }

    // ------------------------------------------------------------
    // Retention sweep dry-run preview
    // ------------------------------------------------------------

    public function test_retention_preview_dispatches_a_dry_run_job_and_mutates_nothing(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->previewRetentionSweepDryRun($admin, $firm);

        Queue::assertPushed(RetentionSweepJob::class, function (RetentionSweepJob $job) use ($firm) {
            return $job->firmId === $firm->id && $job->dryRun === true;
        });

        // Since Queue::fake() prevents the job body from ever running,
        // zero mutation is trivially guaranteed — explicitly reconfirmed
        // here anyway, so this assertion fails loudly if a future change
        // ever makes the preview call something synchronously.
        $fresh = $this->runWithFirmContext($firm, fn () => $event->fresh());
        $this->assertNotNull($fresh, 'The dead-lettered event must still exist untouched after a dry-run preview.');
        $this->assertSame(\App\Integrations\Enums\OutboxEventStatus::DeadLettered, $fresh->status);
    }

    // ------------------------------------------------------------
    // Audit rows for every mutation
    // ------------------------------------------------------------

    public function test_a_successful_requeue_writes_a_security_event_with_correct_actor_and_reason_code(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $audit = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.outbox_event_requeued')
                ->first()
        );

        $this->assertNotNull($audit);
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('platform_integration_oversight', $audit->category);
        $this->assertSame('manual_retry_transient', $audit->metadata['reason_code']);
        $this->assertTrue($audit->metadata['succeeded']);
    }

    public function test_a_failed_requeue_attempt_still_writes_an_audit_row_marked_unsuccessful(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->create()); // pending, ineligible

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $audit = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.outbox_event_requeued')
                ->first()
        );

        $this->assertNotNull($audit, 'An audit row must be written even when the underlying requeue itself was rejected by the guard.');
        $this->assertFalse($audit->metadata['succeeded']);
    }

    public function test_duplicate_requeue_writes_two_audit_rows_reflecting_one_success_and_one_no_op(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->givenActiveCredentialFor($firm, $connection);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');
        $bounded->requeueOutboxEvent($admin, $firm, $event->id, 'manual_retry_transient');

        $audits = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.outbox_event_requeued')
                ->orderBy('id')
                ->get()
        );

        $this->assertCount(2, $audits);
        $this->assertTrue($audits[0]->metadata['succeeded']);
        $this->assertFalse($audits[1]->metadata['succeeded']);
    }

    public function test_nudge_queue_writes_a_security_event(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->nudgeQueue($admin, $firm);

        $audit = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.queue_nudged')
                ->first()
        );

        $this->assertNotNull($audit);
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_retention_preview_writes_a_security_event(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->previewRetentionSweepDryRun($admin, $firm);

        $audit = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.retention_sweep_dry_run_previewed')
                ->first()
        );

        $this->assertNotNull($audit);
        $this->assertSame(PlatformAdmin::class, $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_requeue_actions_never_pass_a_firm_user_id_as_the_actor_only_null(): void
    {
        // Confirmed by reading IntegrationOutboxEventService::requeue()'s
        // 4th parameter contract and PlatformFirmIntegrationBoundedAccessService's
        // call sites — actorFirmUserId is always passed null; structural
        // re-confirmation here.
        $source = file_get_contents(app_path('Services/PlatformFirmIntegrationBoundedAccessService.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('actorFirmUserId: null', $source);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    /**
     * requeue()/requeueFromFailedPermanent()'s own guarded UPDATE also
     * requires an ACTIVE integration_credentials row for the connection
     * — establish one here (mirroring
     * FirmIntegrationsUiSecretSafetyTest's own
     * TenantEncryptionKey::factory()->forFirm()/IntegrationCredential::
     * factory() fixture convention) so "eligible" test cases genuinely
     * satisfy every existing guard clause, not merely the
     * status/firm_id ones.
     */
    private function givenActiveCredentialFor(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
            IntegrationCredential::factory()->forFirmIntegration($connection)->create();
        });
    }
}
