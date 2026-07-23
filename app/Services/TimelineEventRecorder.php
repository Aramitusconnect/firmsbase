<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * TimelineEventRecorder — the ONLY write path into timeline_events.
 * No other service should call TimelineEvent::create() directly; this
 * keeps event-logging centralized instead of scattered ad hoc across
 * every Phase 2+ service. event_type is a plain string (approved
 * decision) — see TimelineEvent's own doc comment for why.
 *
 * Checkpoint 9 addition: $independentOfAmbientTransaction (default
 * false, purely additive — every pre-existing call site is completely
 * unaffected). Every real caller of the governance denial/violation
 * events (IntegrationAccessPolicyService::recordDenied(),
 * FinancialIntegrationAccessPolicyService's equivalents) records the
 * event and then, in the very next statement, throws — and every real
 * call site invokes those assertCan*() methods from inside
 * TenantContextService::runWithFirmContext()'s DB::transaction()
 * closure. Without this flag, the thrown exception unwinds that
 * closure and DB::transaction() rolls back the entire transaction,
 * silently discarding the just-written denial row along with
 * everything else — every single time, with 100% reproducibility,
 * defeating the entire purpose of a denial audit trail. Postgres
 * transactions are all-or-nothing per session, so there is no
 * SAVEPOINT-based fix: the only way to make one write durable
 * independently of the ambient transaction's fate is to perform it on
 * a genuinely separate database session — the 'pgsql_audit' connection
 * (config/database.php), a structural duplicate of the default 'pgsql'
 * connection pointed at the exact same physical database. When true,
 * this method opens its own short-lived transaction on that
 * connection, pushes the RLS session setting the timeline_events
 * FORCE ROW LEVEL SECURITY policy requires (app.current_firm_id — see
 * TenantContextService), and commits — independently of, and before,
 * whatever happens next on the ambient connection/transaction.
 *
 * Precondition callers must honor: $firm must already be committed and
 * visible to a genuinely separate Postgres session at call time. Two
 * independent database sessions can never see each other's uncommitted
 * rows — if $firm was created earlier in the SAME still-open ambient
 * transaction as this call (never true for any real production caller,
 * since a Firm always predates any request that could deny an action
 * against it, but true by default for RefreshDatabase-based tests
 * unless the fixture is deliberately committed on a separate connection
 * first), this insert fails with a timeline_events_firm_id_foreign FK
 * violation instead of producing the durable row this flag exists to
 * guarantee. That failure still propagates and still denies the
 * underlying action — it is not an authorization bypass — but it masks
 * the intended denial exception/message and silently drops the audit
 * row.
 */
class TimelineEventRecorder
{
    private const AUDIT_CONNECTION = 'pgsql_audit';

    private const TENANT_SESSION_SETTING_NAME = 'app.current_firm_id';

    public function record(
        Firm $firm,
        string $eventType,
        ?Model $subject = null,
        ?User $actor = null,
        array $metadata = [],
        bool $independentOfAmbientTransaction = false,
    ): TimelineEvent {
        $attributes = [
            'firm_id' => $firm->id,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'event_type' => $eventType,
            'actor_type' => $actor ? User::class : null,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
            'metadata_json' => $metadata,
        ];

        if (! $independentOfAmbientTransaction) {
            return TimelineEvent::create($attributes);
        }

        return $this->recordOnIndependentConnection($firm, $attributes);
    }

    /**
     * Writes on a dedicated connection/session (self::AUDIT_CONNECTION)
     * so the insert commits independently of whatever the ambient
     * 'pgsql' connection's transaction does afterward. Wrapped in its
     * own transaction purely so the RLS session setting (pushed with
     * SET LOCAL semantics, i.e. is_local=true) and the insert it gates
     * are established together and the setting reverts automatically
     * on commit — never left lingering on this connection for a future
     * reuse.
     */
    private function recordOnIndependentConnection(Firm $firm, array $attributes): TimelineEvent
    {
        $connection = DB::connection(self::AUDIT_CONNECTION);

        return $connection->transaction(function () use ($connection, $firm, $attributes) {
            $connection->statement(
                'select set_config(?, ?, ?)',
                [self::TENANT_SESSION_SETTING_NAME, (string) $firm->id, true]
            );

            return TimelineEvent::on(self::AUDIT_CONNECTION)->create($attributes);
        });
    }
}
