<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * FirmUserAuditEventRecorder — Mission 1C (Security Validation,
 * Activation & Staging Proof), section 19. Closes the "firm-user MFA
 * has ZERO audit trail" finding from Mission 1B's audit: Firm users
 * (`App\Models\User`) get the same real, append-only, FORCE-RLS-backed
 * `security_events` trail Platform Admins already have via
 * `PlatformAdminAuditEventRecorder` — a firm-scoped sibling rather than
 * a generalization of that class, since every one of its methods is
 * type-hinted and hardcodes `actor_type` to `PlatformAdmin`; widening
 * it risks destabilizing an already-tested, security-critical class for
 * a use case (firm_id is always non-null here — a Firm user's own MFA
 * state always belongs to exactly one firm's audit trail) that class
 * was never designed to express.
 *
 * Mirrors `PlatformAdminAuditEventRecorder::record()`'s own proven
 * `TenantContextService::runWithFirmContext()` wrapping exactly.
 */
class FirmUserAuditEventRecorder
{
    public function __construct(private readonly TenantContextService $tenantContext = new TenantContextService) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(Firm $firm, User $actor, string $eventType, string $category, array $metadata = []): void
    {
        $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $actor, $eventType, $category, $metadata): void {
            SecurityEvent::create([
                'firm_id' => $firm->id,
                'actor_type' => User::class,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'category' => $category,
                'metadata' => $metadata,
            ]);
        });
    }
}
