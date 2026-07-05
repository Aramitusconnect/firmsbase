<?php

namespace App\Services;

/**
 * SignatureWorkflowTransitionService — the ONE shared transition graph
 * for the exact 9 master-plan status values, used by BOTH
 * signature_requests.status and signature_request_recipients.status
 * (SignatureRequestStatus is reused verbatim at both scopes — see that
 * enum's docblock for why a second recipient-only vocabulary was not
 * invented). Operates on plain string values (works with the enum's
 * ->value) so the graph is defined exactly once.
 *
 * completed/declined/expired/voided are all terminal — the master plan
 * gives these 9 values with no additional state (e.g. no "archived"),
 * so none is invented here either.
 */
class SignatureWorkflowTransitionService
{
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['sent', 'voided'],
        'sent' => ['viewed', 'declined', 'expired', 'voided'],
        'viewed' => ['consented', 'declined', 'expired', 'voided'],
        'consented' => ['signed', 'declined', 'expired', 'voided'],
        'signed' => ['completed', 'voided'],
        'completed' => [],
        'declined' => [],
        'expired' => [],
        'voided' => [],
    ];

    public function isTransitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    public function assertTransitionAllowed(string $from, string $to): void
    {
        if (! $this->isTransitionAllowed($from, $to)) {
            throw new \RuntimeException("Signature workflow transition from '{$from}' to '{$to}' is not allowed.");
        }
    }
}
