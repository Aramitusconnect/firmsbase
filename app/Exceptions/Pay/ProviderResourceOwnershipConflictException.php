<?php

declare(strict_types=1);

namespace App\Exceptions\Pay;

use RuntimeException;

/**
 * ProviderResourceOwnershipConflictException — FirmsVault Pay Gate A2
 * (v1.4 §6/§7, acceptance tests FV-A-038/FV-A-039). An external
 * provider resource is already owned by a different firm or a different
 * provider account, and ownership can never be reassigned.
 *
 * Deliberately does NOT disclose WHICH firm owns the resource: that
 * would turn the ownership authority into a cross-tenant enumeration
 * oracle, which is exactly what the routing index's own anti-enumeration
 * discipline forbids.
 */
class ProviderResourceOwnershipConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $providerResourceType,
        public readonly string $providerResourceId,
    ) {
        parent::__construct(
            'Provider resource ['.$providerResourceType.'] is already owned by a different firm or '
            .'provider account. Provider-resource tenant ownership is established once and is '
            .'historically immutable; it can never be reassigned.'
        );
    }
}
