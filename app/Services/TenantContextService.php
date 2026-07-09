<?php

namespace App\Services;

use App\Models\Firm;
use Illuminate\Support\Facades\DB;

/**
 * TenantContextService — Section 39A. Bridges the existing PHP-memory
 * tenant context (TenantContextResolver — unchanged, reused as-is) into
 * the PostgreSQL session/transaction setting the RLS policies read
 * (app.current_firm_id, the exact setting name used by every RLS-
 * preparation migration since Phase 1).
 *
 * Section 39A scope (approved decision): this service, its middleware,
 * and its job-context trait are new, fully-tested infrastructure that
 * proves the RLS activation mechanism is correct against the REAL,
 * already-prepared tables — but this section deliberately does NOT
 * flip FORCE ROW LEVEL SECURITY on for the live schema. Doing so today
 * would break every existing test (and any future real usage) that
 * creates/reads tenant-owned rows without first establishing this
 * context, since no code path anywhere yet calls setDatabaseTenantContext()
 * before such an operation — confirmed empirically (see the RLS
 * enforcement tests) and via direct repository search (120+ existing
 * test files create rows on these tables with no context at all). That
 * is a large, cross-cutting test-suite change explicitly out of scope
 * for this "small, reviewable" activation-infrastructure pass; see the
 * report for the residual gap this leaves.
 *
 * Rules:
 *  - set_config()'s third argument (is_local) is chosen adaptively:
 *    true (SET LOCAL semantics) whenever an explicit transaction is
 *    already open (the safe, auto-reverting choice for request/job/
 *    command-scoped work — runWithFirmContext() always uses this
 *    path), false (session-scoped) otherwise, so a caller that sets
 *    context outside of a wrapping transaction (e.g. HTTP middleware
 *    around a whole request) still has it take effect and MUST call
 *    clearDatabaseTenantContext() when done.
 *  - The cleared value is the empty string, matching the existing RLS
 *    policies' own NULLIF(current_setting(...), '') pattern exactly —
 *    empirically confirmed to make current_setting() evaluate to NULL,
 *    which never equals any firm_id (fail closed).
 *  - Never leaves context set past the end of runWithFirmContext(),
 *    even when the callback throws.
 */
class TenantContextService
{
    private const SESSION_SETTING_NAME = 'app.current_firm_id';

    public function setFirmContext(Firm|int|string $firm): void
    {
        (new TenantContextResolver())->activateForFirm($this->resolveFirm($firm));
    }

    public function clearFirmContext(): void
    {
        TenantContextResolver::clear();
    }

    public function currentFirmId(): ?int
    {
        return TenantContextResolver::current()?->firmId;
    }

    public function hasFirmContext(): bool
    {
        return TenantContextResolver::hasContext();
    }

    /**
     * Sets PHP-memory context, wraps the callback in a real database
     * transaction, and pushes the PostgreSQL session setting using SET
     * LOCAL semantics. Also EXPLICITLY clears the database setting in
     * a finally block rather than relying solely on transaction-end
     * auto-revert: when runWithFirmContext() is itself called inside
     * an already-open outer transaction (e.g. RefreshDatabase's own
     * per-test transaction), the inner DB::transaction() becomes a
     * savepoint rather than a true transaction boundary, and Postgres
     * scopes SET LOCAL to the enclosing transaction, not the savepoint
     * — so it would otherwise leak into the rest of that outer
     * transaction. The explicit clear makes this safe regardless of
     * nesting. PHP-memory context is always cleared too, regardless of
     * outcome.
     */
    public function runWithFirmContext(Firm|int|string $firm, callable $callback): mixed
    {
        $this->setFirmContext($firm);

        try {
            return DB::transaction(function () use ($callback) {
                $this->setDatabaseTenantContext();

                return $callback();
            });
        } finally {
            $this->clearDatabaseTenantContext();
            $this->clearFirmContext();
        }
    }

    /**
     * Pushes the CURRENTLY active PHP-memory firm context into the
     * PostgreSQL session/transaction setting the RLS policies read.
     * Throws if no PHP-memory context is active — there is nothing
     * safe to push, and silently no-op-ing here would risk a caller
     * believing tenant context is active when it is not.
     */
    public function setDatabaseTenantContext(): void
    {
        $firmId = $this->currentFirmId();

        if ($firmId === null) {
            throw new \RuntimeException(
                'Cannot set database tenant context: no firm context is currently active. Call setFirmContext() first.'
            );
        }

        DB::select('select set_config(?, ?, ?)', [self::SESSION_SETTING_NAME, (string) $firmId, $this->isLocalScoped()]);
    }

    /**
     * Clears the PostgreSQL session/transaction setting independently
     * of PHP-memory state — safe to call even if no context is active.
     */
    public function clearDatabaseTenantContext(): void
    {
        DB::select('select set_config(?, ?, ?)', [self::SESSION_SETTING_NAME, '', $this->isLocalScoped()]);
    }

    private function isLocalScoped(): bool
    {
        return DB::transactionLevel() > 0;
    }

    private function resolveFirm(Firm|int|string $firm): Firm
    {
        if ($firm instanceof Firm) {
            return $firm;
        }

        if (is_int($firm)) {
            return Firm::query()->findOrFail($firm);
        }

        return Firm::query()->where('uuid', $firm)->firstOrFail();
    }
}
