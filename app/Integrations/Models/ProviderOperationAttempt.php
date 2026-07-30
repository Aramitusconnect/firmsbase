<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * ProviderOperationAttempt — the FK-free durable at-most-once gate row
 * for one logical outbound provider operation (Checkpoint 8.2 §A4).
 *
 * DELIBERATE DIFFERENCES FROM EVERY OTHER MODEL IN THIS DIRECTORY:
 *
 *   1. NO `BelongsToTenant`. This table is registered Global/EXEMPT in
 *      `App\Services\RowLevelSecurityCoverageMappingService` and has no
 *      RLS policy, so there is no session setting for a global scope to
 *      rely on. More importantly, rows are written on a database session
 *      deliberately INDEPENDENT of the caller's transaction, where firm
 *      context is not necessarily established at all — a tenant global
 *      scope would silently return zero rows there, which for a gate
 *      that answers "was this already sent?" fails OPEN. Tenant
 *      filtering is therefore explicit and mandatory: every query lives
 *      in `App\Integrations\Billing\ProviderOperationAttemptService` and
 *      filters on the scalar `firm_id` by hand.
 *
 *   2. NO `belongsTo()` relationships. `firm_id` and
 *      `firm_integration_id` are plain scalars with no foreign keys (see
 *      the create migration's "THIS TABLE INTENTIONALLY HAS NO FOREIGN
 *      KEYS" docblock — Checkpoint 8.1 proved that a cross-session
 *      INSERT whose FK references a row `PullSyncJob` holds FOR UPDATE
 *      deadlocks in production). Exposing eager relations here would
 *      invite exactly the cross-connection joins this design forbids;
 *      callers that need the real connection load it themselves, on the
 *      ordinary connection, under ordinary RLS.
 *
 *   3. NO `HasFactory`. Rows may only come into existence through
 *      `ProviderOperationAttemptService::claim()`, which is what makes
 *      the single-winner guarantee real; a factory would let tests (and
 *      later, production code copied from tests) mint gate rows that
 *      skipped the claim CAS.
 *
 * The service is the sole reader and sole writer. `$guarded` is left as
 * the full-mass-assignment-blocking default rather than a `$fillable`
 * allowlist because no request payload ever reaches this model.
 */
class ProviderOperationAttempt extends Model
{
    use HasPublicUuid;

    protected $table = 'provider_operation_attempts';

    /**
     * Every attribute must be set explicitly by the service — no
     * mass-assignment path exists, by design.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'attempt_state' => ProviderOperationAttemptState::class,
            'operation_version' => 'integer',
            'lease_expires_at' => 'datetime',
            'provider_started_at' => 'datetime',
            'provider_completed_at' => 'datetime',
            'local_processing_completed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * True when this row's state forbids automatically re-sending the
     * provider request. Thin delegation to the enum so callers never
     * re-implement invariant 4's predicate.
     */
    public function forbidsAutomaticResend(): bool
    {
        return $this->attempt_state->forbidsAutomaticResend();
    }

    /**
     * True when the provider is known to have completed the work, so a
     * retry may resume local post-processing WITHOUT calling out again.
     */
    public function providerWorkIsDone(): bool
    {
        return $this->attempt_state->providerWorkIsDone();
    }

    /**
     * True when this row's lease has lapsed — i.e. the worker that
     * claimed it is presumed dead. A lapsed lease is NOT by itself
     * permission to re-send; see
     * ProviderOperationAttemptService::reclaim(), which additionally
     * requires the state to prove the request never left the process.
     */
    public function leaseHasExpired(): bool
    {
        return $this->lease_expires_at !== null && $this->lease_expires_at->isPast();
    }
}
