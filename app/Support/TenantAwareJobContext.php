<?php

namespace App\Support;

use App\Models\Firm;
use App\Services\TenantContextService;

/**
 * TenantAwareJobContext — Section 39A. The established, tested pattern
 * a queued job that touches tenant-owned (RLS-prepared) data should
 * use: run its tenant-scoped work through an explicit firm_id context
 * rather than querying tenant tables with no context at all.
 *
 * Deliberately NOT retrofitted onto the 4 existing job classes
 * (DispatchNotificationJob, RunHealthChecksJob, ScanDocumentJob,
 * WebhookDispatchJob) in this pass — none of them currently rely on
 * RLS enforcement (which is not live for any environment yet, see
 * TenantContextService's docblock), so rewriting them here would be an
 * unrelated, unreviewed behavior change. This trait exists so a job
 * CAN adopt explicit tenant context, and is proven correct by
 * dedicated tests, establishing the expected pattern for future queue
 * work rather than guessing a firm from a bare model id inside the
 * job body.
 */
trait TenantAwareJobContext
{
    /**
     * Runs $callback with the given firm's tenant context active for
     * both the PHP-memory layer and the PostgreSQL session/transaction
     * setting the RLS policies read — never inferred from a model id,
     * always an explicit, caller-supplied firm_id/Firm.
     */
    public function runInFirmContext(Firm|int|string $firm, callable $callback): mixed
    {
        return (new TenantContextService)->runWithFirmContext($firm, $callback);
    }

    /**
     * CHECKPOINT 8.2 addition (§A6). The same tenant context, WITHOUT an
     * enclosing transaction — for a job phase that performs an outbound
     * provider call and therefore must hold neither a transaction nor any
     * row lock across the network window. The job wraps its own atomic
     * units in runInFirmContext() nested inside this one; see
     * TenantContextService::runWithFirmContextWithoutTransaction()'s
     * docblock for the rationale and its stated caveat.
     */
    public function runInFirmContextWithoutTransaction(Firm|int|string $firm, callable $callback): mixed
    {
        return (new TenantContextService)->runWithFirmContextWithoutTransaction($firm, $callback);
    }
}
