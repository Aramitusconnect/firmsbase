<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Models\SecurityEvent;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * PayAuditRecorder — FirmsVault Pay Gate A2 (v1.4 §43). EXTENDS the
 * existing audit infrastructure rather than creating a second one:
 * every event is an ordinary App\Models\SecurityEvent row under one
 * dedicated category, so it appears in the existing platform security
 * dashboards and retention/preservation policy with no new plumbing.
 *
 * NEVER logs secrets, PAN, CVV, provider credentials or canonical
 * payload contents (v1.4 §43). Metadata is restricted to identifiers,
 * enum values, amounts and hash PREFIXES — the same redaction posture
 * as the existing Sanitized* value objects in app/Integrations/Data.
 */
class PayAuditRecorder
{
    public const CATEGORY = 'firmsvault_pay';

    public const INTENT_FROZEN = 'pay.payment_intent.frozen';

    public const INTENT_SUPERSEDED = 'pay.payment_intent.superseded';

    public const COMMAND_CREATED = 'pay.provider_command.created';

    public const COMMAND_IDEMPOTENT_REUSE = 'pay.provider_command.idempotent_reuse';

    public const IDEMPOTENCY_CONFLICT = 'pay.provider_command.idempotency_conflict';

    public const OUTCOME_UNKNOWN = 'pay.outcome_unknown';

    public const REFUND_RESERVED = 'pay.refund.reserved';

    public const REFUND_CAPACITY_REFUSED = 'pay.refund.capacity_refused';

    public const TRUST_EXECUTION_BLOCKED = 'pay.trust_execution.blocked';

    public const OWNERSHIP_ESTABLISHED = 'pay.provider_resource_ownership.established';

    public const OWNERSHIP_CONFLICT = 'pay.provider_resource_ownership.conflict';

    /**
     * `security_events` is FORCE RLS, so an audit write requires real
     * tenant context. Most Pay callers already hold it, but
     * ProviderResourceOwnershipService deliberately does NOT: it is a
     * pre-tenant service by nature (its whole job is resolving which
     * firm owns a resource before any context exists).
     *
     * At the point an audit row is written we always KNOW the firm — it
     * is an argument — so this method establishes context from that
     * known firm rather than requiring every caller to. Nesting is safe:
     * TenantContextService::runWithFirmContext() saves and restores the
     * previous value, so a caller that already holds context for the
     * same firm is unaffected.
     *
     * A null firmId means genuinely firm-less evidence and is written
     * without context; such a row is invisible to every tenant, which is
     * the intended posture.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(string $eventType, ?int $firmId, array $metadata = [], ?int $actorUserId = null): SecurityEvent
    {
        $attributes = [
            'firm_id' => $firmId,
            'actor_type' => $actorUserId === null ? 'system' : 'user',
            'actor_id' => $actorUserId,
            'event_type' => $eventType,
            'category' => self::CATEGORY,
            'metadata' => $this->sanitize($metadata),
        ];

        if (in_array($eventType, self::REFUSAL_EVENTS, true)) {
            return $this->recordOnIndependentConnection($firmId, $attributes);
        }

        $write = fn (): SecurityEvent => SecurityEvent::query()->create($attributes);

        if ($firmId === null) {
            return $write();
        }

        return (new TenantContextService)->runWithFirmContext($firmId, $write);
    }

    /**
     * Events that record a REFUSAL — the operation that produced them
     * is, by definition, about to throw, and the caller's transaction is
     * about to roll back.
     *
     * Writing these on the ambient connection would erase the audit
     * trail of exactly the events most worth auditing: the conflict, the
     * refused refund, the blocked trust execution. That is not
     * hypothetical — it was observed during Gate A2 development, when an
     * idempotency-conflict audit row vanished with the rolled-back
     * transaction that raised it.
     *
     * @var list<string>
     */
    private const REFUSAL_EVENTS = [
        self::IDEMPOTENCY_CONFLICT,
        self::REFUND_CAPACITY_REFUSED,
        self::TRUST_EXECUTION_BLOCKED,
        self::OWNERSHIP_CONFLICT,
    ];

    private const AUDIT_CONNECTION = 'pgsql_audit';

    /**
     * Writes on a dedicated connection/session so the insert commits
     * independently of whatever the ambient transaction does afterward.
     *
     * Mirrors the established precedent
     * App\Services\TimelineEventRecorder::recordOnIndependentConnection()
     * verbatim, including its reason for the wrapping transaction: the
     * RLS session setting is pushed with SET LOCAL semantics
     * (is_local = true) so it is established together with the insert it
     * gates and reverts automatically on commit, never lingering on a
     * pooled connection for a future reuse.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordOnIndependentConnection(?int $firmId, array $attributes): SecurityEvent
    {
        $connection = DB::connection(self::AUDIT_CONNECTION);

        try {
            return $connection->transaction(function () use ($connection, $firmId, $attributes): SecurityEvent {
                if ($firmId !== null) {
                    $connection->statement(
                        'select set_config(?, ?, ?)',
                        ['app.current_firm_id', (string) $firmId, true]
                    );
                }

                return SecurityEvent::on(self::AUDIT_CONNECTION)->create($attributes);
            });
        } catch (\Throwable $e) {
            // AN AUDIT FAILURE MUST NEVER MASK THE DOMAIN REFUSAL IT
            // RECORDS. Every caller of a refusal event is about to throw
            // a precise, meaningful exception (IdempotencyConflict,
            // RefundCapacityExceeded, TrustExecutionDisabled,
            // ProviderResourceOwnershipConflict). If this durable write
            // fails — the independent session cannot see a not-yet-
            // committed firm row, the audit connection is down — letting
            // that failure propagate would replace a clear domain error
            // with an unrelated infrastructure one, and the operator
            // would debug the wrong thing.
            //
            // So: fall back to the ambient connection (best effort; it
            // shares the caller's fate, which is still better than no
            // record at all), and if even that fails, return an
            // unpersisted instance. The domain exception remains the
            // authoritative signal either way.
            report($e);

            try {
                $write = fn (): SecurityEvent => SecurityEvent::query()->create($attributes);

                return $firmId === null
                    ? $write()
                    : (new TenantContextService)->runWithFirmContext($firmId, $write);
            } catch (\Throwable $fallbackFailure) {
                report($fallbackFailure);

                return new SecurityEvent($attributes);
            }
        }
    }

    /**
     * Defence in depth against a future caller passing something it
     * should not. Values are coerced to scalars, long token-shaped
     * strings are truncated to a short prefix, and any key whose name
     * suggests a secret is dropped outright.
     *
     * @param  array<string, scalar|null>  $metadata
     * @return array<string, scalar|null>
     */
    private function sanitize(array $metadata): array
    {
        $forbidden = ['secret', 'token', 'credential', 'password', 'pan', 'cvv', 'card_number', 'payload'];
        $clean = [];

        foreach ($metadata as $key => $value) {
            $lowerKey = strtolower((string) $key);

            foreach ($forbidden as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    continue 2;
                }
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            if (is_string($value) && strlen($value) > 32) {
                $value = substr($value, 0, 12).'…';
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
