<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Integrations\Enums\RequeueIneligibilityReason;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\SyncItemService;
use App\Jobs\OutboxDispatchJob;
use App\Jobs\RetentionSweepJob;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PlatformFirmIntegrationBoundedAccessService — Checkpoint 11 (frozen-
 * design-post-security-review.md §8, §12). The SINGLE chokepoint every
 * Checkpoint 11 Filament page/action MUST go through for (a) every
 * per-firm read and (b) every mutating action against a firm's
 * integration data. Nothing else in this checkpoint calls
 * SupportAccessRequestService/SupportAccessSessionService/
 * SupportAccessPolicyService directly — those three pre-existing
 * (previously zero-call-site) files remain byte-for-byte untouched;
 * this class is their first real caller.
 *
 * Access model (frozen design §2 item 3, §11):
 *   1. PlatformStaffAccessPolicyService::canAccessIntegrationOversight()
 *      is the coarse, role-level gate every method below checks first.
 *   2. For every role that passes (1) but is NOT one of the
 *      unconditionally-trusted ceiling roles (SuperAdmin, PlatformAdmin,
 *      ImplementationSpecialist — matching
 *      PlatformStaffAccessPolicyService::CLIENT_AND_MATTER_DATA_ROLES'
 *      existing, unmodified shape) — i.e. SupportAgent — every per-firm
 *      read/action additionally requires an active, governed
 *      SupportAccessSession scoped to the EXACT target firm. The
 *      always-visible, aggregate/sanitized platform overview itself
 *      needs neither check (it never calls into this class at all).
 *
 * Support-access governance gap closures (frozen design §8) — all four
 * implemented here, as NEW code, never by editing any pre-existing
 * support-access service file:
 *   1. SupportAccessRequestService::request() never calls
 *      logNotification() — requestSupportAccess() below invokes both as
 *      two explicit sequential calls.
 *   2. SupportAccessSessionService::start() never verifies the
 *      session-starter is the original requester —
 *      enterSupportAccessSession() below checks
 *      $request->requested_by === $admin->id before calling start().
 *   3. end()/revoke() have no idempotency guard —
 *      leaveSupportAccessSession()/revokeSupportAccessSession() below
 *      perform their own fresh
 *      SupportAccessSession::query()->where('id', $id)->lockForUpdate()->first()
 *      read immediately before calling end()/revoke(), no-op if already
 *      terminal (mirrors ProviderConnectionService::disconnect()'s own
 *      established idempotent-short-circuit pattern, applied here in
 *      new code).
 *   4. canStartSession()'s approval check is not auto-sequenced before
 *      start() — enterSupportAccessSession() below calls
 *      canStartSession($request) and only calls start() if ->allowed,
 *      re-checked fresh inside this action, never trusted from an
 *      earlier mount()/visible()-time check alone.
 *
 * Operational actions (frozen design §7) — requeueOutboxEvent()/
 * requeueSyncItem()/nudgeQueue()/previewRetentionSweepDryRun() below
 * each ALWAYS call the existing, unmodified
 * IntegrationOutboxEventService::requeue()/
 * SyncItemService::requeueFromFailedPermanent()/
 * OutboxDispatchJob::dispatch()/SyncRetryPollJob::dispatch()/
 * RetentionSweepJob::dispatch() — never a new write path, never a
 * direct model mutation. Every one of these also writes a companion
 * `security_events` audit row (frozen design §4) — mirroring
 * SupportAccessPolicyService::logNotification()/logSessionAudit()'s
 * existing insert shape exactly, since a PlatformAdmin actor cannot be
 * passed through TimelineEventRecorder::record() without a type
 * violation/misattribution (that class remains untouched).
 * $actorFirmUserId is always passed as null to the underlying
 * services — an honest signal (the actor is a PlatformAdmin, not a
 * FirmUser), never a data-integrity defect; real attribution lives in
 * the companion security_events row instead.
 */
final class PlatformFirmIntegrationBoundedAccessService
{
    private const UNCONDITIONALLY_TRUSTED_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::ImplementationSpecialist,
    ];

    private const SECURITY_EVENT_CATEGORY = 'platform_integration_oversight';

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly PlatformRoleService $roleService,
        private readonly SupportAccessRequestService $supportRequests,
        private readonly SupportAccessSessionService $supportSessions,
        private readonly SupportAccessPolicyService $supportPolicy,
        private readonly IntegrationOutboxEventService $outboxEvents,
        private readonly SyncItemService $syncItems,
        private readonly TenantContextService $tenantContext = new TenantContextService(),
    ) {
    }

    // ---------------------------------------------------------------
    // Access gating
    // ---------------------------------------------------------------

    /**
     * The coarse, role-level gate — sufficient on its own for the
     * always-visible platform overview. Throws (never silently denies)
     * so every caller is forced to handle the denial explicitly.
     */
    public function assertCanAccessOversight(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessIntegrationOversight($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access integration oversight.');
        }
    }

    /**
     * True when this admin's active roles include NONE of the
     * unconditionally-trusted ceiling roles — i.e. the admin passes
     * assertCanAccessOversight() (typically via SupportAgent) but still
     * requires a governed, per-firm SupportAccessSession before any
     * per-firm drill-down is allowed.
     */
    public function requiresSupportAccessSession(PlatformAdmin $admin): bool
    {
        foreach ($this->roleService->activeRolesFor($admin) as $role) {
            if (in_array($role, self::UNCONDITIONALLY_TRUSTED_ROLES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The single gate every per-firm read AND every mutating action
     * below passes through, in order: role-level oversight access, then
     * — only for below-unconditional-ceiling roles — an active
     * SupportAccessSession scoped to the exact target firm.
     */
    public function assertCanAccessFirm(PlatformAdmin $admin, Firm $firm): void
    {
        $this->assertCanAccessOversight($admin);

        if (! $this->requiresSupportAccessSession($admin)) {
            return;
        }

        if (! $this->hasActiveSupportAccessSessionFor($admin, $firm)) {
            throw new RuntimeException(
                'An active, governed support access session for this firm is required before this can be viewed or changed.'
            );
        }
    }

    /**
     * Independent, field-level gate for the ONE piece of per-firm data
     * that requires an active session regardless of role ceiling
     * (frozen design §10 item 3: IntegrationConflict.resolution_note is
     * gated behind an active SupportAccessSession for that exact firm,
     * not default-visible — deliberately NOT carved out for
     * unconditionally-trusted roles the way assertCanAccessFirm() is).
     */
    public function hasActiveSupportAccessSessionFor(PlatformAdmin $admin, Firm $firm): bool
    {
        return (bool) $this->tenantContext->runWithFirmContext($firm, fn (): bool => SupportAccessSession::query()
            ->where('platform_admin_id', $admin->id)
            ->where('firm_id', $firm->id)
            ->where('status', SupportAccessSessionStatus::Active->value)
            ->where('expires_at', '>', now())
            ->exists());
    }

    /**
     * Generic per-firm read entry point: enforces assertCanAccessFirm()
     * then runs $callback fresh inside runWithFirmContext(). Every
     * per-firm read in IntegrationPlatformOversightReadService goes
     * through this — data reads and access enforcement are both
     * centralized in this one chokepoint class.
     */
    public function readWithinFirmAccess(PlatformAdmin $admin, Firm $firm, callable $callback): mixed
    {
        $this->assertCanAccessFirm($admin, $firm);

        return $this->tenantContext->runWithFirmContext($firm, $callback);
    }

    // ---------------------------------------------------------------
    // Support access session lifecycle (frozen design §8) — the first
    // real callers of SupportAccessRequestService/SupportAccessSessionService/
    // SupportAccessPolicyService.
    // ---------------------------------------------------------------

    public function requestSupportAccess(
        PlatformAdmin $admin,
        Firm $firm,
        SupportAccessType $accessType,
        string $reason,
        int $requestedDurationMinutes,
        ?string $emergencyJustification = null,
    ): SupportAccessRequest {
        $this->assertCanAccessOversight($admin);

        $request = $this->supportRequests->request(
            $firm,
            $admin,
            $accessType,
            $reason,
            $requestedDurationMinutes,
            $emergencyJustification,
        );

        // Gap closure #1 (frozen design §8 item 1): request() never
        // calls logNotification() itself.
        $this->supportPolicy->logNotification($request, 'support_access.requested');

        return $request;
    }

    public function enterSupportAccessSession(PlatformAdmin $admin, SupportAccessRequest $request): SupportAccessSession
    {
        $this->assertCanAccessOversight($admin);

        // Gap closure #2 (frozen design §8 item 2): start() never
        // verifies the session-starter is the original requester.
        if ((int) $request->requested_by !== (int) $admin->id) {
            throw new RuntimeException('Only the platform admin who requested support access may start this session.');
        }

        // Gap closure #4 (frozen design §8 item 4): canStartSession()'s
        // approval check is not auto-sequenced before start() — checked
        // fresh here, never trusted from an earlier mount()/visible()
        // -time check alone (TOCTOU discipline).
        $decision = $this->supportPolicy->canStartSession($request);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'This support access session may not start.');
        }

        $session = $this->supportSessions->start($request);

        $this->supportPolicy->logSessionAudit($session, 'support_access.session_started');

        return $session;
    }

    public function leaveSupportAccessSession(PlatformAdmin $admin, SupportAccessSession $session): SupportAccessSession
    {
        $this->assertCanAccessOversight($admin);

        return $this->tenantContext->runWithFirmContext($session->firm_id, function () use ($session): SupportAccessSession {
            // Gap closure #3 (frozen design §8 item 3): end() has no
            // idempotency guard of its own — this fresh, locked re-read
            // immediately before calling end() supplies it (new code
            // here; SupportAccessSessionService itself remains
            // untouched), mirroring ProviderConnectionService::
            // disconnect()'s own established idempotent-short-circuit
            // pattern.
            $fresh = SupportAccessSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== SupportAccessSessionStatus::Active) {
                return $fresh;
            }

            $ended = $this->supportSessions->end($fresh);

            $this->supportPolicy->logSessionAudit($ended, 'support_access.session_ended');

            return $ended;
        });
    }

    public function revokeSupportAccessSession(PlatformAdmin $admin, SupportAccessSession $session): SupportAccessSession
    {
        $this->assertCanAccessOversight($admin);

        return $this->tenantContext->runWithFirmContext($session->firm_id, function () use ($admin, $session): SupportAccessSession {
            // Gap closure #3 (frozen design §8 item 3) — identical
            // discipline as leaveSupportAccessSession() above, applied
            // to revoke().
            $fresh = SupportAccessSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== SupportAccessSessionStatus::Active) {
                return $fresh;
            }

            $revoked = $this->supportSessions->revoke($fresh, $admin);

            $this->supportPolicy->logSessionAudit($revoked, 'support_access.session_revoked');

            return $revoked;
        });
    }

    // ---------------------------------------------------------------
    // Operational actions (frozen design §7) — each always calls the
    // existing, unmodified underlying service/job, never a new write
    // path.
    // ---------------------------------------------------------------

    public function requeueOutboxEvent(PlatformAdmin $admin, Firm $firm, int $outboxEventId, string $reasonCode): ?IntegrationOutboxEvent
    {
        $this->assertCanAccessFirm($admin, $firm);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm, $outboxEventId, $reasonCode): ?IntegrationOutboxEvent {
            $result = $this->outboxEvents->requeue($outboxEventId, $firm->id, $reasonCode, actorFirmUserId: null);

            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.outbox_event_requeued', [
                'outbox_event_id' => $outboxEventId,
                'reason_code' => $reasonCode,
                'succeeded' => $result !== null,
            ]);

            return $result;
        });
    }

    public function diagnoseOutboxRequeueIneligibility(PlatformAdmin $admin, Firm $firm, int $outboxEventId): ?RequeueIneligibilityReason
    {
        $this->assertCanAccessFirm($admin, $firm);

        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn (): ?RequeueIneligibilityReason => $this->outboxEvents->diagnoseRequeueIneligibility($outboxEventId, $firm->id)
        );
    }

    public function requeueSyncItem(PlatformAdmin $admin, Firm $firm, int $syncItemId, string $reasonCode): ?IntegrationSyncItem
    {
        $this->assertCanAccessFirm($admin, $firm);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm, $syncItemId, $reasonCode): ?IntegrationSyncItem {
            $result = $this->syncItems->requeueFromFailedPermanent($syncItemId, $firm->id, $reasonCode, actorFirmUserId: null);

            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.sync_item_requeued', [
                'sync_item_id' => $syncItemId,
                'reason_code' => $reasonCode,
                'succeeded' => $result !== null,
            ]);

            return $result;
        });
    }

    public function diagnoseSyncItemRequeueIneligibility(PlatformAdmin $admin, Firm $firm, int $syncItemId): ?RequeueIneligibilityReason
    {
        $this->assertCanAccessFirm($admin, $firm);

        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn (): ?RequeueIneligibilityReason => $this->syncItems->diagnoseRequeueIneligibility($syncItemId, $firm->id)
        );
    }

    /**
     * On-demand per-firm queue nudge — the exact dispatch the scheduler
     * already performs (frozen design §7), never a new dispatch shape.
     */
    public function nudgeQueue(PlatformAdmin $admin, Firm $firm): void
    {
        $this->assertCanAccessFirm($admin, $firm);

        $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm): void {
            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.queue_nudged', []);
        });

        OutboxDispatchJob::dispatch($firm->id);
        SyncRetryPollJob::dispatch($firm->id);
    }

    /**
     * Retention sweep DRY-RUN preview only — zero mutation
     * (RetentionSweepJob::dispatch($firmId, dryRun: true), frozen design
     * §7). The live (non-dry-run) trigger is explicitly out of scope
     * (frozen design §7's rejected list) and is never called anywhere
     * in this class.
     */
    public function previewRetentionSweepDryRun(PlatformAdmin $admin, Firm $firm): void
    {
        $this->assertCanAccessFirm($admin, $firm);

        $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm): void {
            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.retention_sweep_dry_run_previewed', []);
        });

        RetentionSweepJob::dispatch($firm->id, dryRun: true);
    }

    // ---------------------------------------------------------------
    // Audit attribution (frozen design §4) — security_events, never
    // timeline_events/TimelineEventRecorder, never
    // IntegrationRequeueAuditLogger.
    // ---------------------------------------------------------------

    /**
     * Mirrors SupportAccessPolicyService::logNotification()/
     * logSessionAudit()'s existing security_events insert shape exactly
     * (actor_type = PlatformAdmin::class). Must only ever be called from
     * within an already-active runWithFirmContext() call — this method
     * does not establish tenant context itself, matching those two
     * methods' own inner-closure body shape.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function writeOversightAuditEvent(Firm $firm, PlatformAdmin $admin, string $eventType, array $metadata): void
    {
        DB::table('security_events')->insert([
            'firm_id' => $firm->id,
            'actor_type' => PlatformAdmin::class,
            'actor_id' => $admin->id,
            'event_type' => $eventType,
            'category' => self::SECURITY_EVENT_CATEGORY,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);
    }
}
