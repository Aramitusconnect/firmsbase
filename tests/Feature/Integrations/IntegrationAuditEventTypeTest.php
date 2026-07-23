<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TimelineEvent;
use App\Services\TimelineEventRecorder;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationAuditEventTypeTest — Checkpoint 9 (frozen design §3). This
 * codebase has no PHP enum or closed const-array backing
 * `TimelineEvent.event_type` — event names are plain strings, checked
 * ad hoc in each producing service's own test file (the established
 * precedent, e.g. ProviderConnectionServiceOAuthTest's direct
 * `TimelineEvent::query()->where('event_type', '...')` assertions).
 * This file is the closed-taxonomy enumeration for the 15 distinct NEW
 * event name strings this checkpoint introduces (the frozen design's
 * own 14-row table bundles two names into its 14th row): a private,
 * self-documenting const array here IS the closed list (mirroring how
 * RowLevelSecurityCoverageMappingService::discoverForcedTables() derives
 * a closed set from a fixed, enumerable source), cross-checked against
 * every call site actually present in the diffed production files by
 * source-scanning them directly — so a future stray/typo'd event name
 * added to any of these services fails this test the same way a
 * forgotten forced-table entry fails RowLevelSecurityCoverageMappingServiceTest.
 *
 * Also proves each event genuinely fires, with the exact string, at
 * its real call site — not merely that the string is spelled
 * correctly somewhere in source.
 */
class IntegrationAuditEventTypeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The closed, frozen set of 15 distinct new Checkpoint 9 event
     * names. Any new string added to any of the seven producing files
     * below that isn't in this list — or any of these 15 that stops
     * appearing in source — must fail one of the two tests below.
     */
    private const CLOSED_TAXONOMY = [
        'integration_oauth.credential_revoked',
        'integration_oauth.provider_revocation_failed',
        'integration_sync.run_started',
        'integration_sync.run_completed',
        'integration_sync.run_failed',
        'integration_sync.run_cancelled',
        'integration_sync.item_retry_exhausted',
        'integration_outbox.event_dead_lettered',
        'integration_health.state_changed',
        'integration_conflict.detected',
        'integration_conflict.resolved',
        'integration_conflict.expired',
        'integration_governance.action_denied',
        'integration_governance.distinct_approver_violation',
        'integration_governance.distinct_approvers_confirmed',
    ];

    /**
     * Every production file this checkpoint touched that fires one of
     * these events, per the diff review's own call-site trace.
     */
    private const PRODUCER_FILES = [
        'app/Integrations/Services/ProviderConnectionService.php',
        'app/Integrations/Services/SyncRunService.php',
        'app/Integrations/Services/SyncItemService.php',
        'app/Integrations/Services/IntegrationOutboxEventService.php',
        'app/Integrations/Services/HealthStateService.php',
        'app/Integrations/Services/IntegrationConflictService.php',
        'app/Integrations/Services/IntegrationAccessPolicyService.php',
        'app/Integrations/Services/FinancialIntegrationAccessPolicyService.php',
    ];

    /**
     * `ProviderConnectionService.php` is the one producer file that
     * already emitted events BEFORE Checkpoint 9 (its own
     * TimelineEventRecorder dependency and OAuth taxonomy date to
     * Checkpoint 5) — these 10 pre-existing `integration_oauth.*`
     * events are explicitly out of THIS checkpoint's closed-taxonomy
     * scope and must be subtracted from the discovered set below
     * before comparing against CLOSED_TAXONOMY, so this test proves
     * "every NEW event this checkpoint introduced," not "every event
     * that has ever existed in this file."
     */
    private const PRE_EXISTING_EVENT_NAMES = [
        'integration_oauth.authorization_initiated',
        'integration_oauth.authorization_succeeded',
        'integration_oauth.disconnect',
        'integration_oauth.provider_account_mismatch',
        'integration_oauth.reauthorization_succeeded',
        'integration_oauth.refresh_exhausted',
        'integration_oauth.refresh_failed',
        'integration_oauth.refresh_succeeded',
        'integration_oauth.refresh_transient_failure',
        'integration_oauth.required_scope_missing',
    ];

    public function test_the_closed_taxonomy_has_exactly_fifteen_distinct_event_names(): void
    {
        $this->assertCount(15, self::CLOSED_TAXONOMY);
        $this->assertCount(15, array_unique(self::CLOSED_TAXONOMY), 'No duplicate event names in the closed taxonomy.');
    }

    /**
     * Source-scans every producing file for `'integration_<domain>.<name>'`
     * string literals passed as the second argument to
     * TimelineEventRecorder::record() and asserts the discovered set is
     * EXACTLY this test's closed taxonomy — neither a stray new event
     * slipping in unnoticed, nor a documented event silently dropped
     * from source.
     */
    public function test_every_new_event_name_string_literal_in_source_matches_the_closed_taxonomy_exactly_and_nothing_else_is_present(): void
    {
        $discovered = [];

        foreach (self::PRODUCER_FILES as $relativePath) {
            $path = base_path($relativePath);
            $this->assertFileExists($path);
            $source = file_get_contents($path);
            $this->assertIsString($source);

            if (preg_match_all("/'(integration_(?:oauth|sync|outbox|health|conflict|governance)\.[a-z_]+)'/", $source, $matches)) {
                foreach ($matches[1] as $eventName) {
                    $discovered[$eventName] = true;
                }
            }
        }

        $discovered = array_values(array_diff(array_keys($discovered), self::PRE_EXISTING_EVENT_NAMES));
        sort($discovered);

        $expected = self::CLOSED_TAXONOMY;
        sort($expected);

        $this->assertSame(
            $expected,
            $discovered,
            'The discovered set of integration_*.* event-name string literals across the Checkpoint 9 producer files must match the closed taxonomy exactly.'
        );
    }

    // ------------------------------------------------------------
    // Each event genuinely fires, with the exact string, at its real
    // call site.
    // ------------------------------------------------------------

    public function test_sync_run_started_fires_on_successful_startrun_only(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => (new SyncRunService(new TimelineEventRecorder()))->startRun(
            $connection, 'contact', SyncDirection::Inbound, SyncTriggerSource::Manual
        ));

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_sync.run_started')->first());
        $this->assertNotNull($event);
    }

    public function test_sync_run_completed_failed_and_cancelled_fire_on_the_correct_terminal_transition_only(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $service = new SyncRunService(new TimelineEventRecorder());

        $succeededRun = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $this->runWithFirmContext($firm, fn () => $service->transitionStatus($succeededRun, SyncRunStatus::Succeeded));

        $failedRun = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $this->runWithFirmContext($firm, fn () => $service->transitionStatus($failedRun, SyncRunStatus::Failed, 'simulated'));

        $cancelledRun = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $this->runWithFirmContext($firm, fn () => $service->transitionStatus($cancelledRun, SyncRunStatus::Cancelled));

        $completedEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_sync.run_completed')->where('subject_id', $succeededRun->id)->first());
        $failedEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_sync.run_failed')->where('subject_id', $failedRun->id)->first());
        $cancelledEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_sync.run_cancelled')->where('subject_id', $cancelledRun->id)->first());

        $this->assertNotNull($completedEvent);
        $this->assertNotNull($failedEvent);
        $this->assertNotNull($cancelledEvent);
    }

    public function test_sync_item_retry_exhausted_fires_only_on_transition_into_failed_permanent(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $run = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());

        $service = app(SyncItemService::class);

        // A first-attempt Succeeded outcome must never fire the event.
        $this->runWithFirmContext($firm, fn () => $service->recordAttempt(
            $firm->id, $run->id, 'contact', null, null, 'ext-ok', \App\Integrations\Enums\SyncItemStatus::Succeeded
        ));
        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_sync.item_retry_exhausted')->count()));

        // A FailedPermanent outcome must fire it exactly once.
        $this->runWithFirmContext($firm, fn () => $service->recordAttempt(
            $firm->id, $run->id, 'contact', null, null, 'ext-exhausted', \App\Integrations\Enums\SyncItemStatus::FailedPermanent, null, 'exhausted'
        ));

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_sync.item_retry_exhausted')->first());
        $this->assertNotNull($event);
    }

    public function test_outbox_event_dead_lettered_fires_only_on_the_dead_letter_branch(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->processing()->create(['attempts' => 10, 'max_attempts' => 10]));

        $service = app(IntegrationOutboxEventService::class);
        $lockToken = $this->runWithFirmContext($firm, fn () => \Illuminate\Support\Facades\DB::table('integration_outbox_events')->where('id', $event->id)->value('lock_token'));

        $this->runWithFirmContext($firm, fn () => $service->fail($event->id, $lockToken, 'sanitized failure'));

        $auditEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_outbox.event_dead_lettered')->first());
        $this->assertNotNull($auditEvent);
    }

    public function test_health_state_changed_fires_only_on_a_genuine_transition_never_on_the_first_baseline_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $service = new HealthStateService(new TimelineEventRecorder());

        // Baseline: first signal ever for this connection — no prior
        // row exists, so no event.
        $this->runWithFirmContext($firm, fn () => $service->recordSuccess($connection->id, $firm->id));
        $baselineCount = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_health.state_changed')->count());
        $this->assertSame(0, $baselineCount);

        // A second identical success does not transition summary_state
        // (already healthy) — still no event.
        $this->runWithFirmContext($firm, fn () => $service->recordSuccess($connection->id, $firm->id));
        $stillZero = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_health.state_changed')->count());
        $this->assertSame(0, $stillZero);
    }

    public function test_conflict_detected_fires_only_on_the_insert_branch_never_the_do_nothing_branch(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $service = new IntegrationConflictService(new TimelineEventRecorder());

        $this->runWithFirmContext($firm, fn () => $service->recordDetection(
            $connection, 'contact', 'App\\Models\\Contact', 1, 'field_value_mismatch',
            ['a' => 1], ['a' => 2]
        ));

        $this->runWithFirmContext($firm, fn () => $service->recordDetection(
            $connection, 'contact', 'App\\Models\\Contact', 1, 'field_value_mismatch',
            ['a' => 1], ['a' => 2]
        ));

        $count = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_conflict.detected')->count());
        $this->assertSame(1, $count, 'A duplicate detection (DO NOTHING branch) must never fire a second detected event.');
    }

    public function test_conflict_resolved_and_expired_fire_on_their_own_distinct_transitions(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $resolver = $this->createWithFirmContext($firm, fn () => FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]));

        $resolvableConflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create());
        $expirableConflict = $this->createWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration($connection)->create());

        $service = new IntegrationConflictService(new TimelineEventRecorder());

        $this->runWithFirmContext($firm, fn () => $service->transitionStatus($resolvableConflict, ConflictStatus::ResolvedLocalWins, $resolver->id));
        $this->runWithFirmContext($firm, fn () => $service->transitionStatus($expirableConflict, ConflictStatus::Expired));

        $resolvedEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_conflict.resolved')->where('subject_id', $resolvableConflict->id)->first());
        $expiredEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_conflict.expired')->where('subject_id', $expirableConflict->id)->first());

        $this->assertNotNull($resolvedEvent);
        $this->assertNotNull($expiredEvent);
    }

    /**
     * NOTE on transaction boundary: the exception is deliberately
     * caught INSIDE the closure passed to runWithFirmContext() (which
     * wraps its whole body in one DB::transaction()), not outside it —
     * see test_action_denied_event_is_rolled_back_when_the_denial_exception_escapes_the_wrapping_transaction()
     * below for why that distinction is load-bearing, not stylistic.
     */
    public function test_governance_action_denied_fires_on_a_policy_denial(): void
    {
        // Durable Firm required: assertCanConfigure()'s denial writes
        // integration_governance.action_denied on the independent
        // 'pgsql_audit' connection (TimelineEventRecorder::
        // recordOnIndependentConnection()), which cannot see a Firm
        // still uncommitted inside this test's RefreshDatabase
        // transaction. Cleanup is registered via beforeApplicationDestroyed()
        // rather than an inline finally block — see
        // cleanUpDurableFirmAuditTrailAfterRollback()'s own docblock for
        // why an inline finally deadlocks here.
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        $paralegal = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $firm->id]);

        $service = new IntegrationAccessPolicyService(new TimelineEventRecorder());

        $this->runWithFirmContext($firm, function () use ($service, $paralegal) {
            try {
                $service->assertCanConfigure($paralegal);
            } catch (\RuntimeException $e) {
                // expected denial — caught HERE, before the transaction
                // this closure runs inside can be rolled back by it.
            }
        });

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_governance.action_denied')->first());
        $this->assertNotNull($event);
        $this->assertSame('configure', $event->metadata_json['action']);
    }

    public function test_distinct_approver_violation_and_confirmed_companion_fire_on_their_respective_paths(): void
    {
        // Durable Firm required: assertDistinctApprovers($owner, $owner)'s
        // same-actor violation writes
        // integration_governance.distinct_approver_violation on the
        // independent 'pgsql_audit' connection (TimelineEventRecorder::
        // recordOnIndependentConnection()), which cannot see a Firm
        // still uncommitted inside this test's RefreshDatabase
        // transaction. The companion distinct_approvers_confirmed event
        // (success path) does NOT use the independent connection, so it
        // needs no special handling. Cleanup is registered via
        // beforeApplicationDestroyed() — see
        // cleanUpDurableFirmAuditTrailAfterRollback()'s own docblock for
        // why an inline finally deadlocks here.
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        $owner = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);
        $attorney = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $service = new FinancialIntegrationAccessPolicyService(new TimelineEventRecorder());

        $this->runWithFirmContext($firm, function () use ($service, $owner) {
            try {
                $service->assertDistinctApprovers($owner, $owner);
            } catch (\RuntimeException $e) {
                // expected: same-actor violation, caught inside the
                // transaction for the same reason as above.
            }
        });

        $this->runWithFirmContext($firm, fn () => $service->assertDistinctApprovers($owner, $attorney));

        $violationEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_governance.distinct_approver_violation')->first());
        $confirmedEvent = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_governance.distinct_approvers_confirmed')->first());

        $this->assertNotNull($violationEvent);
        $this->assertNotNull($confirmedEvent);
    }

    /**
     * FORMERLY a discovered residual risk, now CLOSED. `IntegrationAccessPolicyService::
     * recordDenied()` writes `integration_governance.action_denied` via
     * TimelineEventRecorder::recordOnIndependentConnection() on the
     * separate 'pgsql_audit' connection, which commits independently of
     * — and before — whatever the ambient connection/transaction does
     * next. Every real call site — e.g. ProviderConnectionService::
     * disconnect()/initiateOAuthConnection() — invokes assertCan*() from
     * inside `TenantContextService::runWithFirmContext()`'s own
     * whole-body `DB::transaction()`, and assertCan*() THROWS
     * immediately after recording the event; that exception propagates
     * out of the real call sites uncaught, and Laravel/PostgreSQL rolls
     * back the entire ambient transaction. Because the audit write
     * already committed on its own independent connection beforehand,
     * it survives that rollback intact.
     *
     * Proven directly here via the REAL production call site
     * (ProviderConnectionService::disconnect()), not a synthetic
     * reconstruction: a Paralegal actor is correctly denied
     * (RuntimeException thrown, disconnect does not proceed) and the
     * denial's own audit event DOES survive.
     *
     * The Firm fixture is created via
     * Firm::factory()->connection('pgsql_audit')->create() (a real,
     * immediate commit) rather than the default RefreshDatabase-wrapped
     * connection — see the cleanUpDurableFirmAuditTrailAfterRollback()
     * docblock below for why: recordOnIndependentConnection()'s write on
     * 'pgsql_audit' cannot see a Firm still uncommitted inside this
     * test's own outer transaction, which would otherwise mask this
     * test's real assertion behind an unrelated foreign-key violation.
     */
    public function test_action_denied_event_is_rolled_back_when_the_denial_exception_escapes_the_wrapping_transaction(): void
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $paralegal = FirmUser::factory()->role(FirmUserRole::Paralegal)->create(['firm_id' => $firm->id]);

        $service = app(\App\Integrations\Services\ProviderConnectionService::class);

        $threw = false;

        try {
            $service->disconnect($connection, $paralegal->user_id);
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Sanity check: the Paralegal actor must genuinely be denied for this test to prove anything.');

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('event_type', 'integration_governance.action_denied')->first());

        $this->assertNotNull(
            $event,
            'The action_denied audit row must survive even though the denial exception propagates out of '.
            'the real call site and rolls back the ambient transaction — TimelineEventRecorder writes this '.
            'event on the independent pgsql_audit connection (see '.
            'TimelineEventRecorder::recordOnIndependentConnection()), which commits before the ambient '.
            'transaction unwinds. If this assertion ever fails, the durability gap has reopened.'
        );
        $this->assertSame('disconnect', $event->metadata_json['action']);
    }

    /**
     * Registers cleanup for a Firm (and any timeline_events rows written
     * against it on the independent 'pgsql_audit' connection) that was
     * created via Firm::factory()->connection('pgsql_audit')->create()
     * to make a denial's durable audit write visible across sessions.
     * Neither row is touched by RefreshDatabase's automatic rollback
     * (that trait only wraps the default 'pgsql' connection), so
     * without this, repeated test runs against the same database would
     * accumulate garbage firms/timeline_events rows indefinitely.
     *
     * MUST run via beforeApplicationDestroyed(), not an inline
     * try/finally in the test body: every FirmUser (and, in
     * test_action_denied_event_is_rolled_back_..., FirmIntegration)
     * created against this Firm on the default connection while
     * RefreshDatabase's own outer transaction is still open holds a
     * Postgres FOR KEY SHARE lock on this Firm row for the FK
     * reference, for the whole remaining life of that transaction —
     * attempting to DELETE the Firm from the separate 'pgsql_audit'
     * session before that transaction rolls back deadlocks (reproduced
     * directly: the cleanup query blocks forever waiting on a
     * `Lock/transactionid` wait event). Registering via
     * beforeApplicationDestroyed() defers this cleanup until AFTER
     * RefreshDatabase's own rollback callback has already run (Laravel
     * invokes these callbacks in registration order, and
     * RefreshDatabase registers its rollback in setUp(), before the
     * test body runs) but before the application container is flushed,
     * so the FK lock is already released and Eloquent/DB facades are
     * still usable.
     *
     * timeline_events has permanent FORCE ROW LEVEL SECURITY, so the
     * DELETE must run with app.current_firm_id set to this firm's id on
     * the SAME 'pgsql_audit' connection performing it — mirrors
     * TimelineEventRecorder::recordOnIndependentConnection()'s own
     * SET LOCAL pattern exactly. firms itself carries no RLS policy, so
     * no context is needed for the second delete. firm_id is
     * nullOnDelete() on timeline_events, so deleting timeline_events
     * rows before the firm avoids leaving them as invisible orphans
     * instead of genuinely removed.
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
}
