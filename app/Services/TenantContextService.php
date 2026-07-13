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

    /**
     * Internal login/panel access wiring — see the
     * 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy
     * migration's own docblock for the exact bootstrap problem this
     * solves (an authenticated user's own firm_users row is otherwise
     * unreadable before any firm context is known). Set ONLY from a
     * genuinely authenticated user's own id — never from arbitrary
     * request input.
     */
    private const USER_SESSION_SETTING_NAME = 'app.current_user_id';

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

    /**
     * Section 39A-3L Phase B6 — the deliberate inverse of
     * runWithFirmContext(): proves NO tenant context is active at the
     * database-session level (not merely relying on it having never
     * been set) for the duration of the callback, then restores
     * whatever PHP-memory/DB-level context was active before, if any.
     * Needed for tables (like backup_restore_tests) whose asymmetric
     * RLS WITH CHECK requires a genuinely context-free session to
     * write a firm_id = NULL platform-wide row — a future caller could
     * invoke such a write from inside another firm-scoped operation,
     * and the platform-wide write must not silently inherit that
     * ambient context.
     *
     * The finally block re-pushes the DB-level session setting via
     * setDatabaseTenantContextForFirmId() BEFORE PHP-memory context
     * (the reverse of runWithFirmContext()'s own order, but harmless
     * here since setDatabaseTenantContextForFirmId() takes the firm id
     * directly and never reads PHP-memory state). This re-push is
     * required, not merely PHP-memory restoration: when this method is
     * nested inside an outer runWithFirmContext($firmA, ...), this
     * method's own DB::transaction() is only a savepoint (not a true
     * transaction boundary), and Postgres scopes SET LOCAL to the
     * enclosing real transaction — a normal savepoint release does NOT
     * revert SET LOCAL, only ROLLBACK TO SAVEPOINT does. Without the
     * explicit re-push, the outer caller's DB-level context would stay
     * cleared for the rest of its transaction even though PHP-memory
     * context looked restored.
     */
    public function runWithoutFirmContext(callable $callback): mixed
    {
        $previousFirmId = $this->currentFirmId();
        $this->clearFirmContext();

        try {
            return DB::transaction(function () use ($callback) {
                $this->clearDatabaseTenantContext();

                return $callback();
            });
        } finally {
            if ($previousFirmId !== null) {
                $this->setDatabaseTenantContextForFirmId($previousFirmId);
                $this->setFirmContext($previousFirmId);
            }
        }
    }

    /**
     * Section 39A-3A — pushes the PostgreSQL session setting for a
     * KNOWN firm id WITHOUT touching PHP-memory context
     * (TenantContextResolver). Deliberately decoupled from
     * setFirmContext()/setDatabaseTenantContext(): those two also
     * activate the app-level "current tenant" that
     * BelongsToTenant's global scope reads, which would silently
     * narrow every OTHER tenant-owned model's queries for the rest of
     * the caller's request/test if left active — a much bigger,
     * cross-cutting behavior change than "make this one write/read
     * against an RLS-protected table visible." This method affects
     * only what PostgreSQL's row security policies see, never what
     * Eloquent's own scoping decides to query.
     */
    public function setDatabaseTenantContextForFirmId(int $firmId): void
    {
        DB::select('select set_config(?, ?, ?)', [self::SESSION_SETTING_NAME, (string) $firmId, $this->isLocalScoped()]);
    }

    /**
     * Bootstrap helper: activates ONLY the narrow self-lookup session
     * setting (app.current_user_id) the firm_users_self_lookup RLS
     * policy reads, runs the callback, and always clears it afterward —
     * never touches app.current_firm_id or PHP-memory firm context at
     * all. Used exclusively to let an authenticated user discover their
     * own firm_users row(s) before any firm context can be known.
     *
     * Wrapped in DB::transaction() for the same reason
     * runWithFirmContext() is: if the callback raises a real SQL-level
     * error (e.g. an RLS policy violation), PostgreSQL aborts the
     * CURRENT transaction until it is rolled back — without this
     * wrapper (a savepoint, when nested inside RefreshDatabase's own
     * transaction), the finally block's own cleanup set_config() call
     * would itself fail against the poisoned transaction, masking the
     * original exception entirely.
     */
    public function withUserContext(int $userId, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($userId, $callback) {
                DB::select('select set_config(?, ?, ?)', [self::USER_SESSION_SETTING_NAME, (string) $userId, $this->isLocalScoped()]);

                return $callback();
            });
        } finally {
            DB::select('select set_config(?, ?, ?)', [self::USER_SESSION_SETTING_NAME, '', $this->isLocalScoped()]);
        }
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
