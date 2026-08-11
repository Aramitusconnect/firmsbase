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
 * FORCE RLS is live (167 tables, see
 * RowLevelSecurityCoverageMappingService), so every job that reads or
 * writes a FORCE-RLS'd table must use this trait or an equivalent
 * explicit context — not "deliberately deferred" the way this
 * docblock used to claim. WebhookDispatchJob already uses it.
 * ScanDocumentJob was retrofitted onto it by Mission 1B (Extreme
 * Security Hardening) after a real bug was found: its unscoped
 * `Document::find()` silently returned null under live FORCE RLS,
 * indistinguishable from the legitimate "already deleted" case —
 * see ScanDocumentJob's own docblock. DispatchNotificationJob and
 * RunHealthChecksJob remain untouched because they delegate every
 * FORCE-RLS read/write to a service that already wraps in
 * `runWithFirmContext` internally — not because RLS doesn't apply to
 * them.
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
