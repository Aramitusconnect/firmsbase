<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\ConnectionHealthSummary;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConnectionHealth;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HealthStateService — the ONLY writer of `integration_connection_health`
 * (Checkpoint 8, agent-8f-health-state-design.md §3-§5; frozen VERBATIM
 * as the canonical interface by
 * agent-8h-architecture-security-review.md §6 — five granular
 * record*() methods, not a single generic recordRefreshOutcome()/
 * RefreshOutcome DTO, which is explicitly superseded). Every
 * record*Error()/recordRateLimited() call takes a
 * SanitizedHealthDiagnostic DTO — never a free string.
 *
 * Every method wraps its write in
 * TenantContextService::runWithFirmContext($firmId, ...) — required,
 * not optional: these methods are called from queued jobs
 * (OutboxDispatchJob, RefreshIntegrationToken, SyncRetryPollJob), which
 * run outside any request-scoped tenant-context middleware.
 *
 * Every write is a single `INSERT ... ON CONFLICT (firm_integration_id)
 * DO UPDATE ... ` upsert (never check-then-write) that ALSO, in the
 * same transaction, updates the three denormalized cache columns on
 * `firm_integrations` (last_health_check_at/last_health_status/
 * error_reason) so the two tables never drift, and recomputes/persists
 * `summary_state` as the final step — summary_state is NEVER
 * independently settable.
 *
 * CHECKPOINT 8 REQUIRED IMPLEMENTATION-TIME CHECK (agent-8h-architecture-
 * security-review.md §1 item 6 / §10's table): `next_retry_at` is read
 * back elsewhere as a future eligibility predicate
 * (`WHERE next_retry_at <= now()`), exactly like
 * `integration_outbox_events.next_attempt_at` — every write to it below
 * therefore uses `statement_timestamp()` and the same
 * `to_timestamp(ceil(extract(epoch from statement_timestamp())))`
 * discipline `IntegrationOutboxEventService::claim()`/`fail()` already
 * establish, never a PHP-bound `now()`/Carbon value for that column.
 * The backoff DELAY itself (an integer count of seconds, not a
 * timestamp) is computed in PHP — per agent-8f §3's own explicit
 * framing, "service-layer, not DB-enforced" — and passed into the SQL
 * only as a bound integer parameter, which carries no timestamp-
 * precision hazard.
 */
final class HealthStateService
{
    public function __construct(private readonly TimelineEventRecorder $events)
    {
    }

    public function recordSuccess(int $firmIntegrationId, int $firmId): void
    {
        (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmIntegrationId, $firmId) {
            $connection = FirmIntegration::query()->where('id', $firmIntegrationId)->first();
            $connectionStatus = $connection?->status ?? ConnectionStatus::Active;

            // Checkpoint 9 addition (frozen design §3): read BEFORE the
            // upsert below, so the "did summary_state actually change"
            // comparison compares against the row's state prior to this
            // call, never its own just-written value.
            $previousSummaryState = DB::table('integration_connection_health')
                ->where('firm_integration_id', $firmIntegrationId)
                ->value('summary_state');

            $summaryState = $this->computeSummaryState($connectionStatus, null, null, 0);
            $uuid = (string) Str::uuid7();

            DB::statement(
                'INSERT INTO integration_connection_health '.
                '(uuid, firm_id, firm_integration_id, summary_state, last_success_at, last_failure_at, '.
                'consecutive_failures, last_failure_category, rate_limited_reset_at, next_retry_at, '.
                'sanitized_diagnostic_summary, last_checked_at, created_at, updated_at) '.
                'VALUES (?, ?, ?, ?, statement_timestamp(), NULL, 0, NULL, NULL, '.
                'to_timestamp(ceil(extract(epoch from statement_timestamp()))), '.
                'NULL, statement_timestamp(), statement_timestamp(), statement_timestamp()) '.
                'ON CONFLICT (firm_integration_id) DO UPDATE SET '.
                'summary_state = EXCLUDED.summary_state, '.
                'last_success_at = EXCLUDED.last_success_at, '.
                'consecutive_failures = 0, '.
                'last_failure_category = NULL, '.
                'rate_limited_reset_at = NULL, '.
                'next_retry_at = to_timestamp(ceil(extract(epoch from statement_timestamp()))), '.
                'sanitized_diagnostic_summary = NULL, '.
                'last_checked_at = EXCLUDED.last_checked_at, '.
                'updated_at = EXCLUDED.updated_at',
                [$uuid, $firmId, $firmIntegrationId, $summaryState->value]
            );

            $this->syncDenormalizedCache($connection, $summaryState, null);
            $this->maybeRecordStateChangeEvent($connection, $previousSummaryState, $summaryState);
        });
    }

    public function recordRateLimited(
        int $firmIntegrationId,
        int $firmId,
        CarbonInterface $resetAt,
        SanitizedHealthDiagnostic $diagnostic,
    ): void {
        $this->recordFailureSignal($firmIntegrationId, $firmId, $diagnostic, Carbon::instance($resetAt));
    }

    public function recordCredentialError(int $firmIntegrationId, int $firmId, SanitizedHealthDiagnostic $diagnostic): void
    {
        $this->recordFailureSignal($firmIntegrationId, $firmId, $diagnostic);
    }

    public function recordScopeError(int $firmIntegrationId, int $firmId, SanitizedHealthDiagnostic $diagnostic): void
    {
        $this->recordFailureSignal($firmIntegrationId, $firmId, $diagnostic);
    }

    public function recordProviderError(int $firmIntegrationId, int $firmId, SanitizedHealthDiagnostic $diagnostic): void
    {
        $this->recordFailureSignal($firmIntegrationId, $firmId, $diagnostic);
    }

    /**
     * Relies on BelongsToTenant's automatic scoping when a firm context
     * is already active (the normal case for anything reached through
     * an already-tenant-scoped caller) plus FORCE RLS as the actual
     * enforcement boundary regardless — mirrors agent-8f §5's own
     * "read side" design exactly.
     */
    public function summaryFor(FirmIntegration $integration): ConnectionHealthSummary
    {
        $row = IntegrationConnectionHealth::query()
            ->where('firm_integration_id', $integration->id)
            ->first();

        return $this->toSummaryDto($row);
    }

    /**
     * @return Collection<int, ConnectionHealthSummary>
     */
    public function summariesForFirm(int $firmId): Collection
    {
        return (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmId) {
            return IntegrationConnectionHealth::query()
                ->where('firm_id', $firmId)
                ->get()
                ->map(fn (IntegrationConnectionHealth $row) => $this->toSummaryDto($row))
                ->values();
        });
    }

    private function toSummaryDto(?IntegrationConnectionHealth $row): ConnectionHealthSummary
    {
        if ($row === null) {
            return new ConnectionHealthSummary(HealthSummaryState::Healthy, null, null, 0, null, null);
        }

        return new ConnectionHealthSummary(
            $row->summary_state,
            $row->last_success_at,
            $row->last_failure_at,
            $row->consecutive_failures,
            $row->next_retry_at,
            $row->sanitized_diagnostic_summary,
        );
    }

    /**
     * Shared implementation for the four record*Error()/recordRateLimited()
     * methods — differs only in whether a provider-declared $resetAt is
     * present. $existingFailures is read via a plain SELECT immediately
     * before the upsert (service-layer computation, per agent-8f §3 —
     * not a claim/lock primitive; a rare concurrent-write race here only
     * approximates the backoff delay slightly, never a security/data-
     * integrity hazard).
     */
    private function recordFailureSignal(
        int $firmIntegrationId,
        int $firmId,
        SanitizedHealthDiagnostic $diagnostic,
        ?Carbon $resetAt = null,
    ): void {
        (new TenantContextService())->runWithFirmContext(
            $firmId,
            function () use ($firmIntegrationId, $firmId, $diagnostic, $resetAt) {
                $connection = FirmIntegration::query()->where('id', $firmIntegrationId)->first();
                $connectionStatus = $connection?->status ?? ConnectionStatus::Active;

                // Checkpoint 9 addition (frozen design §3): read BEFORE
                // the upsert below, mirroring recordSuccess()'s
                // identical "compare against prior state" discipline.
                $previousSummaryState = DB::table('integration_connection_health')
                    ->where('firm_integration_id', $firmIntegrationId)
                    ->value('summary_state');

                $existingFailures = (int) (DB::table('integration_connection_health')
                    ->where('firm_integration_id', $firmIntegrationId)
                    ->value('consecutive_failures') ?? 0);

                $newFailures = $existingFailures + 1;

                $baseDelay = (int) config('integrations.health.backoff_base_seconds', 60);
                $maxDelay = (int) config('integrations.health.backoff_max_seconds', 3600);
                $delaySeconds = (int) min($baseDelay * (2 ** max(0, $newFailures - 1)), $maxDelay);

                $summaryState = $this->computeSummaryState($connectionStatus, $diagnostic->category(), $resetAt, $newFailures);
                $uuid = (string) Str::uuid7();
                $summaryText = $diagnostic->toSummaryText();

                if ($resetAt !== null) {
                    DB::statement(
                        'INSERT INTO integration_connection_health '.
                        '(uuid, firm_id, firm_integration_id, summary_state, last_success_at, last_failure_at, '.
                        'consecutive_failures, last_failure_category, rate_limited_reset_at, next_retry_at, '.
                        'sanitized_diagnostic_summary, last_checked_at, created_at, updated_at) '.
                        'VALUES (?, ?, ?, ?, NULL, statement_timestamp(), 1, ?, ?, '.
                        'GREATEST(to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), ?::timestamp), '.
                        '?, statement_timestamp(), statement_timestamp(), statement_timestamp()) '.
                        'ON CONFLICT (firm_integration_id) DO UPDATE SET '.
                        'summary_state = EXCLUDED.summary_state, '.
                        'last_failure_at = EXCLUDED.last_failure_at, '.
                        'consecutive_failures = integration_connection_health.consecutive_failures + 1, '.
                        'last_failure_category = EXCLUDED.last_failure_category, '.
                        'rate_limited_reset_at = EXCLUDED.rate_limited_reset_at, '.
                        'next_retry_at = GREATEST(to_timestamp(ceil(extract(epoch from statement_timestamp()))) + (? * interval \'1 second\'), ?::timestamp), '.
                        'sanitized_diagnostic_summary = EXCLUDED.sanitized_diagnostic_summary, '.
                        'last_checked_at = EXCLUDED.last_checked_at, '.
                        'updated_at = EXCLUDED.updated_at',
                        [
                            $uuid, $firmId, $firmIntegrationId, $summaryState->value, $diagnostic->category(), $resetAt,
                            $delaySeconds, $resetAt, $summaryText,
                            $delaySeconds, $resetAt,
                        ]
                    );
                } else {
                    DB::statement(
                        'INSERT INTO integration_connection_health '.
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
                        'updated_at = EXCLUDED.updated_at',
                        [
                            $uuid, $firmId, $firmIntegrationId, $summaryState->value, $diagnostic->category(),
                            $delaySeconds, $summaryText,
                            $delaySeconds,
                        ]
                    );
                }

                $this->syncDenormalizedCache($connection, $summaryState, $summaryText);
                $this->maybeRecordStateChangeEvent($connection, $previousSummaryState, $summaryState);
            }
        );
    }

    /**
     * Checkpoint 9 addition (frozen design §3):
     * `integration_health.state_changed` fires ONLY on an actual
     * `HealthSummaryState` transition — never on every poll. A null
     * $previousSummaryState (no prior row exists yet) is treated as
     * "establishing a baseline," not a transition, so the very first
     * health signal for a connection never fires this event.
     */
    private function maybeRecordStateChangeEvent(
        ?FirmIntegration $connection,
        string|null $previousSummaryState,
        HealthSummaryState $newSummaryState,
    ): void {
        if ($connection === null || $previousSummaryState === null) {
            return;
        }

        if ($previousSummaryState === $newSummaryState->value) {
            return;
        }

        $this->events->record($connection->firm, 'integration_health.state_changed', $connection, null, [
            'firm_integration_id' => $connection->id,
            'from' => $previousSummaryState,
            'to' => $newSummaryState->value,
        ]);
    }

    /**
     * Deterministic derivation rule (agent-8f-health-state-design.md
     * §2) — summary_state is NEVER independently settable; it is always
     * recomputed from ConnectionStatus + the accumulated signal columns.
     */
    private function computeSummaryState(
        ConnectionStatus $connectionStatus,
        ?string $lastFailureCategory,
        ?Carbon $rateLimitedResetAt,
        int $consecutiveFailures,
    ): HealthSummaryState {
        if ($connectionStatus === ConnectionStatus::Disconnected) {
            return HealthSummaryState::Unavailable;
        }

        if (in_array($connectionStatus, [
            ConnectionStatus::ReauthorizationRequired,
            ConnectionStatus::ScopeInsufficient,
            ConnectionStatus::Error,
        ], true)) {
            return HealthSummaryState::ActionRequired;
        }

        if (in_array($lastFailureCategory, [
            SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
            SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR,
        ], true)) {
            return HealthSummaryState::ActionRequired;
        }

        if ($lastFailureCategory === SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED
            && $rateLimitedResetAt !== null
            && $rateLimitedResetAt->isFuture()) {
            return HealthSummaryState::Degraded;
        }

        if ($lastFailureCategory === SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR) {
            if ($consecutiveFailures >= (int) config('integrations.health.unavailable_after_failures', 3)) {
                return HealthSummaryState::Unavailable;
            }

            if ($consecutiveFailures >= (int) config('integrations.health.degraded_after_failures', 1)) {
                return HealthSummaryState::Degraded;
            }
        }

        return HealthSummaryState::Healthy;
    }

    /**
     * Keeps firm_integrations.last_health_check_at/last_health_status/
     * error_reason as a denormalized last-known-state cache, written
     * transactionally by this SAME call so the two tables never drift
     * (agent-8f-health-state-design.md §1).
     */
    private function syncDenormalizedCache(?FirmIntegration $connection, HealthSummaryState $summaryState, ?string $errorReason): void
    {
        if ($connection === null) {
            return;
        }

        FirmIntegration::query()->where('id', $connection->id)->update([
            'last_health_check_at' => now(),
            'last_health_status' => $summaryState->value,
            'error_reason' => $errorReason,
        ]);
    }
}
