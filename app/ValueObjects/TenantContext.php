<?php

namespace App\ValueObjects;

use App\Enums\DeploymentMode;

/**
 * TenantContext — the ONLY shape application code should consume to
 * know "which firm am I operating as right now" (project rule 11 —
 * tenancy abstraction layer). deploymentMode is informational metadata
 * only (e.g. for future connection routing); it must never be branched
 * on inside feature/business logic.
 *
 * Immutable — a context is resolved once per request/job and swapped
 * in as a new instance, never mutated in place.
 */
final readonly class TenantContext
{
    public function __construct(
        public int $firmId,
        public string $firmUuid,
        public ?int $organizationId,
        public DeploymentMode $deploymentMode,
    ) {
    }

    public function equals(TenantContext $other): bool
    {
        return $this->firmId === $other->firmId;
    }
}
