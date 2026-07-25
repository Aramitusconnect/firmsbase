<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;

/**
 * PlatformAdminAuditEventRecorder — the generic, reusable write path for
 * a `security_events` row attributed to a PlatformAdmin actor. Modeled
 * directly on the shape hand-rolled at 3 existing call sites
 * (SupportAccessPolicyService::logNotification()/logSessionAudit(),
 * PlatformFirmIntegrationBoundedAccessService::writeOversightAuditEvent())
 * — every one of those inserts the exact same column shape:
 * firm_id/actor_type=PlatformAdmin::class/actor_id/event_type/category/
 * metadata(json)/created_at, wrapped in
 * TenantContextService::runWithFirmContext() (security_events carries
 * FORCE ROW LEVEL SECURITY — Section 39A-3L Phase B6 — so a raw insert
 * with no active app.current_firm_id session setting is rejected by its
 * WITH CHECK policy).
 *
 * Deliberately NOT a refactor of those 3 existing call sites — they stay
 * byte-for-byte untouched (that is a separate, more invasive change
 * requiring its own review, per this mission's explicit scope). This
 * class exists so any NEW Phase 1+ code (the Policy classes, the new
 * Filament Resources/Pages, and their future mutating Actions) has one
 * correct, reusable place to write this row instead of hand-rolling a
 * fourth (or fifth, or sixth) copy of the same insert shape.
 *
 * Self-wrapping design: unlike
 * PlatformFirmIntegrationBoundedAccessService::writeOversightAuditEvent()
 * (private, and deliberately assumes an ALREADY-active firm context
 * because its callers are always already inside one, avoiding a
 * redundant nested runWithFirmContext() call), this class establishes
 * its OWN firm context on every call — mirroring
 * SupportAccessPolicyService::logNotification()/logSessionAudit()'s
 * self-contained shape instead. This makes it safely callable from
 * anywhere (no caller-side discipline required about pre-existing
 * context), at the cost of a nested transaction/SET LOCAL when a caller
 * happens to already be inside one — TenantContextService::
 * runWithFirmContext() is documented as safe/re-entrant for exactly this
 * case (it snapshots and restores whatever context was active before
 * it ran). A future caller that is already deep inside a
 * runWithFirmContext() closure for the exact same firm and wants to
 * avoid that nested overhead may still choose to hand-roll the insert
 * the way writeOversightAuditEvent() does — this class does not forbid
 * that, it just does not require it.
 */
class PlatformAdminAuditEventRecorder
{
    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * Writes one `security_events` row attributed to $actor (a
     * PlatformAdmin), scoped to $firm. `category` mirrors every existing
     * call site's own vocabulary (e.g. 'support_access',
     * 'platform_integration_oversight') — callers choose their own
     * category string; this class does not enumerate or validate it,
     * matching every existing call site's behavior.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Firm $firm,
        PlatformAdmin $actor,
        string $eventType,
        string $category,
        array $metadata = [],
    ): void {
        $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $actor, $eventType, $category, $metadata): void {
            DB::table('security_events')->insert([
                'firm_id' => $firm->id,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'category' => $category,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * FirmsVault Admin Control Center MFA design proposal §7. `record()`
     * above is unusable for MFA events (login/enroll/disable/reset/
     * emergency-command) — those are not tied to any one firm, and
     * `security_events.firm_id` is nullable specifically for this case
     * (see that column's own migration docblock: "platform-level events
     * ... are legitimate rows here"). The
     * 2026_08_25_930034_force_rls_on_security_events_table migration's
     * WITH CHECK policy only accepts a null-firm_id insert when NO
     * app.current_firm_id session setting is active at all (never when
     * some OTHER firm's context happens to be active) — so this method
     * deliberately uses TenantContextService::runWithoutFirmContext()
     * (explicit clear + restore of whatever context was active before,
     * exactly mirroring AppServiceProvider's own Login/Failed
     * guard-event listeners' null-firm_id write pattern) rather than
     * record()'s runWithFirmContext().
     *
     * Additive to the existing record() method, not a replacement or a
     * second recorder class — same table, same column shape, same
     * actor-attribution convention, just a different (no-firm) context
     * strategy for the one nullable column.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordPlatformEvent(
        PlatformAdmin $actor,
        string $eventType,
        string $category,
        array $metadata = [],
    ): void {
        $this->tenantContext->runWithoutFirmContext(function () use ($actor, $eventType, $category, $metadata): void {
            DB::table('security_events')->insert([
                'firm_id' => null,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'category' => $category,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * FirmsVault Admin Control Center MFA design proposal §8/runbook —
     * the platform-admin:emergency-mfa-reset Artisan command's own audit
     * write. Deliberately NOT recordPlatformEvent(PlatformAdmin $actor,
     * ...): that method (and
     * PlatformAdminMfaResetService::reset(PlatformAdmin $actingSuperAdmin,
     * ...), which requires a real, currently-active PlatformAdmin actor)
     * both assume a genuine authenticated-panel actor exists to attribute
     * the action to. The emergency command exists precisely for the
     * scenario where that assumption can be FALSE (a sole SuperAdmin who
     * has lost both their device and their recovery codes has, by
     * definition, no other active SuperAdmin available to perform an
     * in-panel reset on their behalf) — forcing a real PlatformAdmin
     * actor onto this call site would mean either fabricating one
     * (misattribution) or making the emergency path unusable in exactly
     * the scenario it exists for. Attribution here instead comes from
     * `actor_type = 'console'`/`actor_id = null` plus whatever operator
     * signal the command itself gathers into `metadata` (reason, OS
     * user, hostname) — a real but categorically different kind of
     * evidence than an authenticated PlatformAdmin row, made explicit
     * rather than disguised as one. Still the same table, same
     * recorder class, same no-firm-context write strategy as
     * recordPlatformEvent() above — never a silent, unaudited path.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordConsoleEvent(
        string $eventType,
        string $category,
        array $metadata = [],
    ): void {
        $this->tenantContext->runWithoutFirmContext(function () use ($eventType, $category, $metadata): void {
            DB::table('security_events')->insert([
                'firm_id' => null,
                'actor_type' => 'console',
                'actor_id' => null,
                'event_type' => $eventType,
                'category' => $category,
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }
}
