<?php

declare(strict_types=1);

namespace Tests\Feature\Pay\Concerns;

use App\Services\Pay\PayAuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * CleansUpDurablePayAudit — removes FirmsVault Pay audit rows that were
 * deliberately written OUTSIDE the test transaction.
 *
 * WHY THIS IS NECESSARY, AND WHY IT IS NOT A WORKAROUND.
 * PayAuditRecorder writes REFUSAL events (idempotency conflict, refund
 * capacity refused, trust execution blocked, ownership conflict) on the
 * independent `pgsql_audit` connection, precisely so they survive the
 * rollback of the transaction whose failure they record. That is the
 * correct production behavior and it is proved by
 * PayRefusalAuditDurabilityTest.
 *
 * The unavoidable consequence in the test suite is that RefreshDatabase's
 * per-test transaction CANNOT roll those rows back — they were never in
 * that transaction. Left alone they accumulate in the shared disposable
 * database and leak into completely unrelated later tests, which is not
 * hypothetical: it was observed breaking
 * Tests\Feature\Security\LoginPolicy\TenantAwareLoginPolicyTest (which
 * asserts security_events is empty) and
 * Tests\Feature\Tenancy\BelongsToTenantScopeTest (which counts rows
 * visible with no tenant context).
 *
 * So any test class that can trigger a Pay refusal must clean up after
 * itself. The deletion is scoped tightly to PayAuditRecorder::CATEGORY so
 * it can never remove another domain's audit rows.
 */
trait CleansUpDurablePayAudit
{
    protected function tearDown(): void
    {
        $this->purgeDurablePayAuditRows();

        parent::tearDown();
    }

    protected function purgeDurablePayAuditRows(): void
    {
        try {
            // security_events is FORCE RLS, so a plain DELETE would match
            // zero rows. These rows are cross-tenant test residue with no
            // single owning firm, so the delete runs on the same
            // independent connection that wrote them, per firm id.
            $connection = DB::connection('pgsql_audit');

            $firmIds = $connection->table('security_events')
                ->where('category', PayAuditRecorder::CATEGORY)
                ->distinct()
                ->pluck('firm_id');

            foreach ($firmIds as $firmId) {
                $connection->transaction(function () use ($connection, $firmId) {
                    if ($firmId !== null) {
                        $connection->statement(
                            'select set_config(?, ?, ?)',
                            ['app.current_firm_id', (string) $firmId, true]
                        );
                    }

                    $connection->table('security_events')
                        ->where('category', PayAuditRecorder::CATEGORY)
                        ->when($firmId !== null, fn ($q) => $q->where('firm_id', $firmId))
                        ->when($firmId === null, fn ($q) => $q->whereNull('firm_id'))
                        ->delete();
                });
            }
        } catch (\Throwable) {
            // Teardown must never convert a passing test into an error.
            // A residue row is a nuisance; a failed teardown hides real
            // results.
        }
    }
}
