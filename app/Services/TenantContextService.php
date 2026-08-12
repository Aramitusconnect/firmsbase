<?php

namespace App\Services;

use App\Models\Firm;
use App\ValueObjects\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * TenantContextService — Section 39A. Bridges the existing PHP-memory
 * tenant context (TenantContextResolver — unchanged, reused as-is) into
 * the PostgreSQL session/transaction setting the RLS policies read
 * (app.current_firm_id, the exact setting name used by every RLS-
 * preparation migration since Phase 1).
 *
 * Section 39A scope (approved decision, HISTORICAL): this service, its
 * middleware, and its job-context trait started as new, fully-tested
 * infrastructure that proved the RLS activation mechanism was correct
 * against the REAL, already-prepared tables, before FORCE ROW LEVEL
 * SECURITY was flipped on for the live schema. That activation is no
 * longer deferred: the many FORCE-RLS-activation waves since Section
 * 39A completed the rollout (see
 * RowLevelSecurityCoverageMappingService::forcedTables(), currently
 * 167 tables). Every request/job/command that reads or writes a
 * FORCE-RLS'd table MUST establish context through this service (or a
 * caller that already wraps it, e.g. TenantAwareJobContext) — omitting
 * it is a real bug today, not a deferred concern. (Mission 1B, Extreme
 * Security Hardening, corrected this paragraph after finding it had
 * gone stale following the rollout — see ScanDocumentJob's own
 * docblock for the concrete bug that caused.)
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
 *  - runWithFirmContext() restores whatever context (if any) was active
 *    immediately before it was called, rather than unconditionally
 *    clearing — it may be nested inside an already-active ambient
 *    context (e.g. HTTP middleware that set context for the whole
 *    request, such as FirmPanelProvider's EstablishFirmTenantContext).
 *    An unconditional clear would wipe that outer context for the rest
 *    of the request/job instead of merely undoing this call's own
 *    effect. This restore happens even when the callback throws.
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

    /**
     * Checkpoint 4 ("Plaid financial evidence add-on"), Client Portal
     * authentication foundation — the one remaining RLS bootstrap hop.
     * An earlier draft of this checkpoint also had a first hop (Set
     * ONLY from `Auth::guard('client')->id()`, read by a
     * `client_portal_users_self_lookup` policy) — `client_portal_users`
     * has since been reclassified System (no RLS at all, identical
     * treatment to `users`; see that table's own create-migration
     * docblock), so that first hop's session setting
     * (CLIENT_PORTAL_SESSION_SETTING_NAME/withClientPortalUserContext())
     * no longer has any policy to satisfy and was removed. `clients`
     * remains BelongsToTenant + FORCE-RLS protected, so this one hop is
     * still genuinely required. Set ONLY from an already-resolved
     * `ClientPortalUser.client_id` value, read via an ordinary,
     * unwrapped query (no RLS to satisfy on that table) — never from
     * arbitrary request input.
     */
    private const CLIENT_SELF_LOOKUP_SESSION_SETTING_NAME = 'app.current_client_id';

    /**
     * Payment Link / QR Routing phase — the RLS bootstrap hop for the
     * one genuinely public, unauthenticated payment page. A visitor
     * arrives holding nothing but a payment_requests.uuid (from a
     * signed URL/QR code) — no firm context can exist yet, exactly the
     * same bootstrap problem firm_users_self_lookup and
     * clients_self_lookup already solve for their own tables. Set
     * ONLY from the already-resolved uuid the caller itself supplies
     * (the route parameter, never trusted further than that) — see
     * withPaymentRequestSelfLookupContext() below.
     */
    private const PAYMENT_REQUEST_SELF_LOOKUP_SESSION_SETTING_NAME = 'app.current_payment_request_uuid';

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 1 — the
     * same RLS bootstrap problem as PAYMENT_REQUEST_SELF_LOOKUP above,
     * for a genuinely anonymous prospect resuming their own
     * marketplace_intakes row via a resumable intake link. Set ONLY
     * from the already-resolved uuid the caller itself supplies (the
     * route parameter, never trusted further than that) — see
     * withMarketplaceIntakeSelfLookupContext() below.
     */
    private const MARKETPLACE_INTAKE_SELF_LOOKUP_SESSION_SETTING_NAME = 'app.current_marketplace_intake_uuid';

    public function setFirmContext(Firm|int|string $firm): void
    {
        (new TenantContextResolver)->activateForFirm($this->resolveFirm($firm));
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
     * LOCAL semantics. Saves whatever context (PHP-memory and database)
     * was active before the call, and restores exactly that in a
     * finally block — rather than unconditionally clearing — because
     * when runWithFirmContext() is itself called inside an already-open
     * outer transaction (e.g. RefreshDatabase's own per-test
     * transaction) OR inside an already-active ambient, non-transaction
     * -scoped context (e.g. HTTP middleware that set context for the
     * whole request), an unconditional clear would wipe that outer
     * context instead of merely undoing this call's own effect. This
     * restore happens regardless of nesting and even when the callback
     * throws.
     */
    public function runWithFirmContext(Firm|int|string $firm, callable $callback): mixed
    {
        $previousContext = TenantContextResolver::current();
        $previousDatabaseFirmId = $this->currentDatabaseTenantContextValue();

        $this->setFirmContext($firm);

        try {
            return DB::transaction(function () use ($callback) {
                $this->setDatabaseTenantContext();

                return $callback();
            });
        } finally {
            $this->restoreDatabaseTenantContext($previousDatabaseFirmId);
            $this->restoreFirmContext($previousContext);
        }
    }

    /**
     * CHECKPOINT 8.2 addition (§A6/§A11). Identical to
     * runWithFirmContext() in every respect EXCEPT that it does not open
     * a database transaction: the context is established with
     * session-scoped `set_config` (because `isLocalScoped()` is false
     * outside a transaction) and restored in the same `finally`.
     *
     * WHY THIS EXISTS. A caller that performs an outbound provider HTTP
     * call must not hold a database transaction across it, and must not
     * hold row locks across it — Checkpoint 8.1 proved that a lock held
     * over the network window deadlocks anything that needs to write
     * durably about the same connection. `App\Jobs\PullSyncJob` used to
     * wrap its ENTIRE run, provider calls included, in one
     * runWithFirmContext() transaction. It now claims ownership in a
     * short transaction, runs the provider calls under this method, and
     * applies each page's local writes in its own short
     * runWithFirmContext() transaction nested inside this one.
     *
     * This is not a new context mechanism. A session-scoped, ambient,
     * non-transactional firm context is exactly what the `firm` panel's
     * HTTP middleware already establishes for a whole request
     * (EstablishFirmTenantContext + ApplyTenantDatabaseContext), and
     * nesting runWithFirmContext() inside such an ambient context is
     * already proven to restore rather than wipe it — see
     * TenantContextServiceSessionScopedNestingTest.
     *
     * CAVEAT, stated plainly: because there is no transaction, the
     * callback's writes are NOT atomic with each other. Only use this for
     * work whose atomic units are established by the callback itself
     * (PullSyncJob's per-page runWithFirmContext() blocks), never as a
     * cheaper substitute for runWithFirmContext().
     */
    public function runWithFirmContextWithoutTransaction(Firm|int|string $firm, callable $callback): mixed
    {
        $previousContext = TenantContextResolver::current();
        $previousDatabaseFirmId = $this->currentDatabaseTenantContextValue();

        $this->setFirmContext($firm);

        try {
            $this->setDatabaseTenantContext();

            return $callback();
        } finally {
            $this->restoreDatabaseTenantContext($previousDatabaseFirmId);
            $this->restoreFirmContext($previousContext);
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
     * Executes a callback with both PHP-memory and PostgreSQL tenant
     * context explicitly cleared, then restores the exact context state
     * that existed beforehand.
     *
     * Both layers are snapshotted independently because some legitimate
     * internal callers deliberately set only the PostgreSQL context via
     * setDatabaseTenantContextForFirmId() without activating PHP-memory
     * tenant context. Restoring only currentFirmId() would silently wipe
     * that database-only outer context.
     */
    public function runWithoutFirmContext(callable $callback): mixed
    {
        $previousContext = TenantContextResolver::current();
        $previousDatabaseFirmId = $this->currentDatabaseTenantContextValue();

        $this->clearFirmContext();

        try {
            return DB::transaction(function () use ($callback) {
                $this->clearDatabaseTenantContext();

                return $callback();
            });
        } finally {
            $this->restoreDatabaseTenantContext($previousDatabaseFirmId);
            $this->restoreFirmContext($previousContext);
        }
    }

    /**
     * Reads the CURRENTLY active database session/transaction setting,
     * without touching it. Returns null for both "never set" and
     * "explicitly cleared to empty string" — the same equivalence the
     * RLS policies themselves rely on (NULLIF(current_setting(...), '')).
     * Used by runWithFirmContext() to snapshot the prior value so it can
     * be restored afterward instead of unconditionally cleared.
     */
    private function currentDatabaseTenantContextValue(): ?string
    {
        $value = DB::selectOne('select current_setting(?, true) as value', [self::SESSION_SETTING_NAME])?->value;

        return $value === '' ? null : $value;
    }

    /**
     * Restores a previously-snapshotted database session/transaction
     * setting (from currentDatabaseTenantContextValue()) — null restores
     * to the cleared empty-string state, matching clearDatabaseTenantContext().
     */
    private function restoreDatabaseTenantContext(?string $previousFirmId): void
    {
        DB::select('select set_config(?, ?, ?)', [self::SESSION_SETTING_NAME, $previousFirmId ?? '', $this->isLocalScoped()]);
    }

    /**
     * Restores a previously-snapshotted PHP-memory context (from
     * TenantContextResolver::current(), taken before runWithFirmContext()
     * overwrote it) — null restores to the cleared state.
     */
    private function restoreFirmContext(?TenantContext $previousContext): void
    {
        if ($previousContext === null) {
            TenantContextResolver::clear();
        } else {
            TenantContextResolver::set($previousContext);
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

    /**
     * Checkpoint 4 ("Plaid financial evidence add-on"), Client Portal
     * authentication foundation — the one remaining RLS bootstrap hop
     * (the fix Finding 2 of checkpoint4-security-review.md required).
     * Activates ONLY the narrow app.current_client_id session setting
     * the clients_self_lookup RLS policy reads, runs the callback, and
     * always clears it afterward. Never touches app.current_firm_id or
     * PHP-memory firm context.
     *
     * An earlier draft of this checkpoint had a preceding hop
     * (withClientPortalUserContext(), reading `client_portal_users` via
     * a self-lookup RLS policy) — `client_portal_users` has since been
     * reclassified System (no RLS at all, identical treatment to
     * `users`), so that method was removed entirely; there is nothing
     * left for it to unlock. $clientId is now resolved via an ordinary,
     * unwrapped query against `client_portal_users` (no RLS to satisfy
     * on that table), never from request input, query string, or
     * header — this is what keeps the chain attacker-proof end to end.
     */
    public function withClientSelfLookupContext(int $clientId, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($clientId, $callback) {
                DB::select('select set_config(?, ?, ?)', [self::CLIENT_SELF_LOOKUP_SESSION_SETTING_NAME, (string) $clientId, $this->isLocalScoped()]);

                return $callback();
            });
        } finally {
            DB::select('select set_config(?, ?, ?)', [self::CLIENT_SELF_LOOKUP_SESSION_SETTING_NAME, '', $this->isLocalScoped()]);
        }
    }

    /**
     * Payment Link / QR Routing phase. Identical in shape to
     * withClientSelfLookupContext() — activates ONLY the narrow
     * app.current_payment_request_uuid session setting the
     * payment_requests_self_lookup RLS policy reads, runs the
     * callback, and always clears it afterward. Never touches
     * app.current_firm_id or PHP-memory firm context. $uuid must be a
     * value the caller already has independently (the public route's
     * own uuid parameter) — this method grants no more than "find the
     * one payment_requests row with this exact uuid," never a listing
     * or any other table.
     */
    public function withPaymentRequestSelfLookupContext(string $uuid, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($uuid, $callback) {
                DB::select('select set_config(?, ?, ?)', [self::PAYMENT_REQUEST_SELF_LOOKUP_SESSION_SETTING_NAME, $uuid, $this->isLocalScoped()]);

                return $callback();
            });
        } finally {
            DB::select('select set_config(?, ?, ?)', [self::PAYMENT_REQUEST_SELF_LOOKUP_SESSION_SETTING_NAME, '', $this->isLocalScoped()]);
        }
    }

    /**
     * Sets the app.current_marketplace_intake_uuid session setting the
     * marketplace_intakes_self_lookup RLS policy reads, runs the
     * callback, and always clears it afterward. Never touches
     * app.current_firm_id or PHP-memory firm context. $uuid must be a
     * value the caller already has independently (the public route's
     * own uuid parameter) — this method grants no more than "find the
     * one marketplace_intakes row with this exact uuid," never a
     * listing or any other table. Mirrors
     * withPaymentRequestSelfLookupContext() exactly.
     */
    public function withMarketplaceIntakeSelfLookupContext(string $uuid, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($uuid, $callback) {
                DB::select('select set_config(?, ?, ?)', [self::MARKETPLACE_INTAKE_SELF_LOOKUP_SESSION_SETTING_NAME, $uuid, $this->isLocalScoped()]);

                return $callback();
            });
        } finally {
            DB::select('select set_config(?, ?, ?)', [self::MARKETPLACE_INTAKE_SELF_LOOKUP_SESSION_SETTING_NAME, '', $this->isLocalScoped()]);
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
