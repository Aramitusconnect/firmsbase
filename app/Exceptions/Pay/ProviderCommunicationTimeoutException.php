<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * ProviderCommunicationTimeoutException — FirmsVault Pay Gate A3
 * (v1.4 §10/§14). The transport-level "we cannot tell what happened"
 * signal: the request may or may not have been processed by the
 * provider.
 *
 * The ONLY correct reaction is OUTCOME_UNKNOWN on the existing
 * attempt/refund, retaining the original ProviderCommand and its
 * idempotency identity. Catching this and retrying the send is exactly
 * the double-charge bug the whole architecture exists to prevent.
 */
class ProviderCommunicationTimeoutException extends RuntimeException {}
