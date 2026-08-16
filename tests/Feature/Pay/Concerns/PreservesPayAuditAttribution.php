<?php

declare(strict_types=1);

namespace Tests\Feature\Pay\Concerns;

use App\Services\Pay\PayAuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * PreservesPayAuditAttribution — the correct remediation for Gate A2's
 * durable-audit test residue, and a replacement for an earlier
 * "CleansUpDurablePayAudit" trait that could never have worked.
 *
 * ============================================================
 * WHY DELETING THE ROWS IS IMPOSSIBLE (and must stay impossible)
 * ============================================================
 * `security_events` carries exactly two RLS policies:
 *
 *   security_events_platform_write   FOR INSERT
 *   security_events_tenant_isolation FOR SELECT
 *
 * There is NO policy for DELETE or UPDATE. Under FORCE ROW LEVEL
 * SECURITY, a command with no permissive policy matches ZERO rows — so a
 * DELETE against security_events silently affects nothing, for every
 * role, in every tenant context, forever. That is not an oversight: a
 * security audit log is append-only BY DESIGN, and the absence of a
 * DELETE policy is what enforces it.
 *
 * An earlier remediation attempt tried to have each test delete its own
 * Pay audit rows under tenant context. It was verified to be structurally
 * impossible: the row is readable under context (SELECT policy) and the
 * DELETE returns 0 (no DELETE policy). Making it work would require
 * adding a DELETE policy to the audit log, which would be a real
 * weakening of an audit control, not a test fix.
 *
 * ============================================================
 * WHAT ACTUALLY CAUSED THE LEAK
 * ============================================================
 * Not the rows existing — the rows becoming ORPHANED.
 *
 * The SELECT policy is
 *     firm_id = <current firm>  OR  (firm_id IS NULL AND <current firm> IS NULL)
 *
 * so with NO tenant context a reader sees ONLY rows whose firm_id IS
 * NULL. A Pay audit row that keeps a real firm_id is therefore invisible
 * to a contextless reader and harms nobody.
 *
 * `security_events.firm_id` is ON DELETE SET NULL. The moment a test
 * deleted its own firm in teardown, every audit row that firm owned was
 * orphaned to firm_id = NULL — and instantly became globally visible to
 * exactly the contextless assertions in
 * Tests\Feature\Security\LoginPolicy\TenantAwareLoginPolicyTest
 * (assertDatabaseCount('security_events', 0)) and
 * Tests\Feature\Tenancy\BelongsToTenantScopeTest
 * (assertSame(3, SecurityEvent::count())).
 *
 * ============================================================
 * THE FIX
 * ============================================================
 * Do not orphan them. A test that writes durable Pay audit rows keeps its
 * firm row, so the audit trail stays attributed and stays invisible to
 * contextless readers. Every OTHER fixture row the test created is still
 * cleaned up normally (those tables do have FOR ALL policies).
 *
 * This changes no production behavior, adds no RLS bypass, adds no NULL
 * policy, does not touch FORCE RLS, and does not alter the ON DELETE
 * semantics of security_events.
 *
 * The guard below turns the invariant into an assertion, so a future test
 * that reintroduces firm deletion fails immediately and locally instead
 * of breaking an unrelated suite hundreds of tests later.
 */
trait PreservesPayAuditAttribution
{
    /**
     * Fails the calling test if any Pay-category audit row has been
     * orphaned to firm_id = NULL.
     *
     * Deliberately reads with NO tenant context: that is precisely the
     * reader whose visibility matters here, and under the SELECT policy
     * it can only ever see NULL-firm rows. It is a read, never a delete.
     */
    protected function assertNoOrphanedPayAuditRows(): void
    {
        $orphaned = DB::table('security_events')
            ->where('category', PayAuditRecorder::CATEGORY)
            ->whereNull('firm_id')
            ->count();

        $this->assertSame(
            0,
            $orphaned,
            'Orphaned FirmsVault Pay audit rows (firm_id = NULL) are visible to every contextless reader '
            .'and cannot be deleted (security_events has no DELETE policy under FORCE RLS). A test that '
            .'writes durable Pay audit rows must NOT delete its own firm — see '
            .'Tests\\Feature\\Pay\\Concerns\\PreservesPayAuditAttribution.'
        );
    }
}
