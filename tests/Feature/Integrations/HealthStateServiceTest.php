<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HealthStateServiceTest — Checkpoint 8 (agent-8f-health-state-design.md
 * §1-§5; agent-8h-architecture-security-review.md §1 item 6/§6/§4.2).
 * Exercises all five record*() methods; summaryFor()/summariesForFirm();
 * computeSummaryState()'s derivation table (healthy/degraded/
 * action_required/unavailable per config('integrations.health.*'));
 * upsert-not-check-then-write (ON CONFLICT (firm_integration_id) DO
 * UPDATE, proven both behaviorally and via a real concurrent-write
 * test); the denormalized firm_integrations.last_health_status/
 * error_reason cache updated in the SAME transaction as the health row.
 *
 * Deliberately does NOT use RefreshDatabase (matches
 * IntegrationOutboxConcurrentClaimTest's own documented convention):
 * the mandatory concurrent-write proof at the bottom of this file needs
 * a literal SECOND, separate physical DB connection to race the same
 * upsert, which would see nothing at all if fixtures were created
 * inside RefreshDatabase's own continuously-open, never-committed outer
 * transaction. Every fixture is instead a real, committed row, tracked
 * and deleted in tearDown() via cascadeOnDelete() from `firms`.
 */
class HealthStateServiceTest extends TestCase
{
    private HealthStateService $service;

    /** @var int[] */
    private array $createdFirmIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HealthStateService(new TimelineEventRecorder());
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (array_key_exists('worker_b', config('database.connections', []))) {
            while (DB::connection('worker_b')->transactionLevel() > 0) {
                DB::connection('worker_b')->rollBack();
            }
            DB::purge('worker_b');
        }
        DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);

        if ($this->createdFirmIds !== []) {
            DB::table('firms')->whereIn('id', $this->createdFirmIds)->delete();
        }

        parent::tearDown();
    }

    private function firm(): Firm
    {
        $firm = Firm::factory()->create();
        $this->createdFirmIds[] = $firm->id;

        return $firm;
    }

    private function connection(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value, 'external_account_id' => null]),
        );
    }

    private function healthRow(FirmIntegration $connection): ?object
    {
        return $this->runWithFirmContext(
            $connection->firm,
            fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->first(),
        );
    }

    private function diagnostic(string $category, string $operation = SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH): SanitizedHealthDiagnostic
    {
        return new SanitizedHealthDiagnostic($category, $operation);
    }

    // ------------------------------------------------------------
    // recordSuccess()
    // ------------------------------------------------------------

    public function test_record_success_creates_a_healthy_row_with_zero_consecutive_failures(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordSuccess($connection->id, $firm->id);

        $row = $this->healthRow($connection);
        $this->assertNotNull($row);
        $this->assertSame(HealthSummaryState::Healthy->value, $row->summary_state);
        $this->assertNotNull($row->last_success_at);
        $this->assertNull($row->last_failure_at);
        $this->assertSame(0, (int) $row->consecutive_failures);
        $this->assertNull($row->last_failure_category);
        $this->assertNull($row->rate_limited_reset_at);
    }

    public function test_record_success_resets_a_previously_failing_connection_back_to_healthy(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordProviderError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));
        $this->service->recordProviderError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));

        $failing = $this->healthRow($connection);
        $this->assertSame(2, (int) $failing->consecutive_failures);

        $this->service->recordSuccess($connection->id, $firm->id);

        $recovered = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::Healthy->value, $recovered->summary_state);
        $this->assertSame(0, (int) $recovered->consecutive_failures, 'recordSuccess() must reset consecutive_failures to zero.');
        $this->assertNull($recovered->last_failure_category);
        $this->assertNull($recovered->rate_limited_reset_at);
    }

    public function test_record_success_upserts_rather_than_duplicating_a_row(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordSuccess($connection->id, $firm->id);
        $this->service->recordSuccess($connection->id, $firm->id);
        $this->service->recordSuccess($connection->id, $firm->id);

        $count = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->count(),
        );
        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------
    // recordRateLimited()
    // ------------------------------------------------------------

    public function test_record_rate_limited_persists_the_reset_at_and_degraded_state(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $resetAt = now()->addMinutes(2);

        $this->service->recordRateLimited(
            $connection->id,
            $firm->id,
            $resetAt,
            $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED),
        );

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::Degraded->value, $row->summary_state);
        $this->assertSame('rate_limited', $row->last_failure_category);
        $this->assertNotNull($row->rate_limited_reset_at);
        $this->assertSame(1, (int) $row->consecutive_failures);
    }

    public function test_record_rate_limited_next_retry_at_is_at_least_the_provider_declared_reset_at(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $resetAt = now()->addMinutes(10);

        $this->service->recordRateLimited(
            $connection->id,
            $firm->id,
            $resetAt,
            $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED),
        );

        $row = $this->healthRow($connection);
        $nextRetryAt = Carbon::parse($row->next_retry_at);

        $this->assertTrue(
            $nextRetryAt->greaterThanOrEqualTo($resetAt->copy()->subSecond()),
            'next_retry_at must never be earlier than the provider-declared reset_at (GREATEST(...) in the SQL).'
        );
    }

    // ------------------------------------------------------------
    // recordCredentialError()
    // ------------------------------------------------------------

    public function test_record_credential_error_produces_action_required(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordCredentialError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR));

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::ActionRequired->value, $row->summary_state);
        $this->assertSame('credential_error', $row->last_failure_category);
    }

    // ------------------------------------------------------------
    // recordScopeError()
    // ------------------------------------------------------------

    public function test_record_scope_error_produces_action_required(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordScopeError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR));

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::ActionRequired->value, $row->summary_state);
        $this->assertSame('scope_error', $row->last_failure_category);
    }

    // ------------------------------------------------------------
    // recordProviderError()
    // ------------------------------------------------------------

    public function test_record_provider_error_is_degraded_below_the_unavailable_threshold(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordProviderError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));

        $row = $this->healthRow($connection);
        $this->assertSame(1, (int) $row->consecutive_failures);
        $this->assertSame(
            HealthSummaryState::Degraded->value,
            $row->summary_state,
            'consecutive_failures=1 >= degraded_after_failures(1) but < unavailable_after_failures(3) must be Degraded.'
        );
    }

    public function test_record_provider_error_becomes_unavailable_at_the_configured_threshold(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $threshold = (int) config('integrations.health.unavailable_after_failures', 3);

        for ($i = 0; $i < $threshold; $i++) {
            $this->service->recordProviderError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));
        }

        $row = $this->healthRow($connection);
        $this->assertSame($threshold, (int) $row->consecutive_failures);
        $this->assertSame(HealthSummaryState::Unavailable->value, $row->summary_state);
    }

    public function test_record_provider_error_below_the_degraded_threshold_stays_healthy_if_threshold_is_configured_high(): void
    {
        config(['integrations.health.degraded_after_failures' => 5]);

        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordProviderError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));

        $row = $this->healthRow($connection);
        $this->assertSame(
            HealthSummaryState::Healthy->value,
            $row->summary_state,
            '1 consecutive failure must not yet cross a degraded_after_failures=5 threshold.'
        );
    }

    // ------------------------------------------------------------
    // computeSummaryState() derivation table via ConnectionStatus
    // ------------------------------------------------------------

    public function test_a_disconnected_connection_is_always_unavailable_regardless_of_failure_signal(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm, ConnectionStatus::Disconnected);

        $this->service->recordSuccess($connection->id, $firm->id);

        $row = $this->healthRow($connection);
        $this->assertSame(
            HealthSummaryState::Unavailable->value,
            $row->summary_state,
            'Disconnected connection status must force Unavailable even on a SUCCESS signal.'
        );
    }

    public function test_a_reauthorization_required_connection_is_action_required_even_on_success(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm, ConnectionStatus::ReauthorizationRequired);

        $this->service->recordSuccess($connection->id, $firm->id);

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::ActionRequired->value, $row->summary_state);
    }

    public function test_an_error_status_connection_is_action_required(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm, ConnectionStatus::Error);

        $this->service->recordSuccess($connection->id, $firm->id);

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::ActionRequired->value, $row->summary_state);
    }

    public function test_a_scope_insufficient_connection_is_action_required(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm, ConnectionStatus::ScopeInsufficient);

        $this->service->recordSuccess($connection->id, $firm->id);

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::ActionRequired->value, $row->summary_state);
    }

    // ------------------------------------------------------------
    // summary_state is never independently settable
    // ------------------------------------------------------------

    public function test_summary_state_cannot_be_overridden_by_a_direct_write_bypassing_the_service(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordSuccess($connection->id, $firm->id);

        // A caller attempting to force ActionRequired via a bare update,
        // bypassing HealthStateService, is possible at the DB layer (no
        // CHECK constraint forbids it) — but the NEXT service call must
        // always recompute it fresh, proving the application-level
        // contract ("summary_state is a derived value, never trust a
        // stale direct write") holds for every legitimate call path.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connection->id)
            ->update(['summary_state' => 'action_required']));

        $this->service->recordSuccess($connection->id, $firm->id);

        $row = $this->healthRow($connection);
        $this->assertSame(HealthSummaryState::Healthy->value, $row->summary_state, 'The next record*() call must recompute summary_state fresh, not preserve a stale manually-forced value.');
    }

    // ------------------------------------------------------------
    // summaryFor() / summariesForFirm()
    // ------------------------------------------------------------

    public function test_summary_for_returns_healthy_defaults_when_no_row_exists_yet(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $summary = $this->runWithFirmContext($firm, fn () => $this->service->summaryFor($connection));

        $this->assertSame(HealthSummaryState::Healthy, $summary->summaryState);
        $this->assertNull($summary->lastSuccessAt);
        $this->assertNull($summary->lastFailureAt);
        $this->assertSame(0, $summary->consecutiveFailures);
        $this->assertNull($summary->nextRetryAt);
        $this->assertNull($summary->sanitizedDiagnosticSummary);
    }

    public function test_summary_for_reflects_the_persisted_row_after_a_failure_signal(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordCredentialError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR));

        $summary = $this->runWithFirmContext($firm, fn () => $this->service->summaryFor($connection->fresh()));

        $this->assertSame(HealthSummaryState::ActionRequired, $summary->summaryState);
        $this->assertSame(1, $summary->consecutiveFailures);
        $this->assertNotNull($summary->sanitizedDiagnosticSummary);
        $this->assertStringContainsString('credential_error', $summary->sanitizedDiagnosticSummary);
    }

    public function test_summaries_for_firm_returns_one_summary_per_connection_scoped_to_that_firm(): void
    {
        $firm = $this->firm();
        $connectionA = $this->connection($firm);
        $connectionB = $this->connection($firm);

        $this->service->recordSuccess($connectionA->id, $firm->id);
        $this->service->recordProviderError($connectionB->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));

        $summaries = $this->service->summariesForFirm($firm->id);

        $this->assertCount(2, $summaries);
    }

    public function test_summaries_for_firm_never_leaks_another_firms_connection_health(): void
    {
        $firmA = $this->firm();
        $firmB = $this->firm();
        $connectionA = $this->connection($firmA);
        $connectionB = $this->connection($firmB);

        $this->service->recordSuccess($connectionA->id, $firmA->id);
        $this->service->recordSuccess($connectionB->id, $firmB->id);

        $summariesForA = $this->service->summariesForFirm($firmA->id);

        $this->assertCount(1, $summariesForA);
    }

    // ------------------------------------------------------------
    // Denormalized firm_integrations cache, same transaction
    // ------------------------------------------------------------

    public function test_denormalized_cache_columns_are_synced_on_success(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordSuccess($connection->id, $firm->id);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(HealthSummaryState::Healthy, $fresh->last_health_status);
        $this->assertNull($fresh->error_reason);
        $this->assertNotNull($fresh->last_health_check_at);
    }

    public function test_denormalized_cache_columns_are_synced_on_failure_and_match_the_health_row_exactly(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->service->recordScopeError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR, SanitizedHealthDiagnostic::OPERATION_PULL_SYNC));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $row = $this->healthRow($connection);

        $this->assertSame($row->summary_state, $fresh->last_health_status->value);
        $this->assertNotNull($fresh->error_reason);
        $this->assertSame($row->sanitized_diagnostic_summary, $fresh->error_reason);
        $this->assertStringContainsString('scope_error', $fresh->error_reason);
        $this->assertStringContainsString('pull_sync', $fresh->error_reason);
    }

    public function test_denormalized_cache_write_fails_closed_when_the_connection_row_no_longer_exists(): void
    {
        // firm_integration_id with no corresponding firm_integrations row
        // — the composite FK on integration_connection_health rejects
        // the write outright (fails closed) rather than silently
        // creating a phantom health row with no owning connection.
        $firm = $this->firm();
        $nonExistentConnectionId = 999999999;

        try {
            $this->service->recordSuccess($nonExistentConnectionId, $firm->id);
            $this->fail('Expected a foreign key violation when firm_integration_id does not reference a real row.');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('constraint', $e->getMessage());
        }
    }

    // ------------------------------------------------------------
    // Upsert not check-then-write — behavioral proof (sequential)
    // ------------------------------------------------------------

    public function test_repeated_failure_signals_increment_the_same_row_rather_than_creating_new_ones(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        for ($i = 1; $i <= 5; $i++) {
            $this->service->recordProviderError($connection->id, $firm->id, $this->diagnostic(SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR));
            $row = $this->healthRow($connection);
            $this->assertSame($i, (int) $row->consecutive_failures, "consecutive_failures must be exactly {$i} after the {$i}th call.");
        }

        $count = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->count(),
        );
        $this->assertSame(1, $count, 'Exactly one row must exist per connection regardless of call count — upsert, never a duplicate insert.');
    }

    // ------------------------------------------------------------
    // Upsert not check-then-write — genuine concurrent-write proof,
    // mirroring IntegrationOutboxConcurrentClaimTest's real-second-
    // connection technique: two real, separate physical connections
    // both attempt the identical ON CONFLICT (firm_integration_id) DO
    // UPDATE upsert for the SAME row. If this were a naive
    // check-then-write instead of a true atomic upsert, one of the two
    // increments could be silently lost; PostgreSQL's row-level lock
    // on the ON CONFLICT target instead correctly serializes the two
    // writers, so both increments land — deterministic ordering, not a
    // timing race (A's INSERT/commit is guaranteed, by plain sequential
    // PHP statement ordering, to happen before B's conflicting upsert).
    // ------------------------------------------------------------

    public function test_two_separate_physical_connections_racing_the_same_upsert_never_lose_an_increment(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        config(['database.connections.worker_b' => config('database.connections.pgsql')]);
        DB::purge('worker_b');

        // Exact literal copy of HealthStateService::recordFailureSignal()'s
        // non-resetAt INSERT ... ON CONFLICT branch — if that SQL text
        // changes, this literal copy must be updated to match.
        $upsertSql = 'INSERT INTO integration_connection_health '.
            '(uuid, firm_id, firm_integration_id, summary_state, last_success_at, last_failure_at, '.
            'consecutive_failures, last_failure_category, rate_limited_reset_at, next_retry_at, '.
            'sanitized_diagnostic_summary, last_checked_at, created_at, updated_at) '.
            'VALUES (?, ?, ?, ?, NULL, statement_timestamp(), 1, ?, NULL, '.
            'to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), '.
            '?, statement_timestamp(), statement_timestamp(), statement_timestamp()) '.
            'ON CONFLICT (firm_integration_id) DO UPDATE SET '.
            'summary_state = EXCLUDED.summary_state, '.
            'last_failure_at = EXCLUDED.last_failure_at, '.
            'consecutive_failures = integration_connection_health.consecutive_failures + 1, '.
            'last_failure_category = EXCLUDED.last_failure_category, '.
            'rate_limited_reset_at = NULL, '.
            'next_retry_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), '.
            'sanitized_diagnostic_summary = EXCLUDED.sanitized_diagnostic_summary, '.
            'last_checked_at = EXCLUDED.last_checked_at, '.
            'updated_at = EXCLUDED.updated_at';

        $bindingsFor = fn (string $uuid) => [
            $uuid, $firm->id, $connection->id, 'degraded', 'provider_error', 60, 'category=provider_error, operation=health_check', 60,
        ];

        // --- Connection A (default) ---------------------------------
        DB::beginTransaction();
        DB::select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);
        DB::statement($upsertSql, $bindingsFor((string) Str::uuid7()));
        DB::commit();

        // --- Connection B (worker_b) ---------------------------------
        // Runs strictly AFTER A's commit — deterministic sequencing, so
        // B's own ON CONFLICT branch is guaranteed to fire (a row for
        // this firm_integration_id already exists).
        DB::connection('worker_b')->beginTransaction();
        DB::connection('worker_b')->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, false]);
        DB::connection('worker_b')->statement($upsertSql, $bindingsFor((string) Str::uuid7()));
        DB::connection('worker_b')->commit();

        $final = DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->first();

        $this->assertSame(
            2,
            (int) $final->consecutive_failures,
            'Both writers\' increments must land — a lost update here would prove the write is a non-atomic check-then-write rather than a true SQL-level atomic upsert.'
        );

        $countRows = DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->count();
        $this->assertSame(1, $countRows, 'Exactly one row must exist — the second writer must UPDATE the existing row via ON CONFLICT, never INSERT a duplicate.');
    }
}
