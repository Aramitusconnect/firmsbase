<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Enums\SupportAccessType;
use App\Integrations\Enums\RequeueIneligibilityReason;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\ProviderConnectionService;
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
 * Post-review fixes (CHECKPOINT_11_SECURITY_IMPLEMENTATION_REJECTED
 * Findings 1-2), applied in the same spirit as the four gap closures
 * above — new code here, SupportAccessPolicyService/SupportAccessSessionService
 * still untouched:
 *   5. leaveSupportAccessSession() had no session-ownership check —
 *      unlike enterSupportAccessSession()'s requested_by check above,
 *      "leave is self-service only" (LeaveSupportAccessSessionAction's
 *      own docblock) was previously enforced only by a Filament UI
 *      form-options constraint. Now checked explicitly here:
 *      $session->platform_admin_id === $admin->id, or denied.
 *   6. SupportAccessPolicyService::logSessionAudit() used to ALWAYS
 *      attribute actor_id = $session->platform_admin_id — the ORIGINAL
 *      session holder, never the admin who actually performed a revoke.
 *      Wrong specifically for RevokeSupportAccessSessionAction, whose
 *      entire purpose is letting one admin revoke a DIFFERENT admin's
 *      session.
 *      leaveSupportAccessSession()/revokeSupportAccessSession() below
 *      call writeOversightAuditEvent() (the mechanism already used
 *      correctly elsewhere in this file, for requeue/nudge/retention-
 *      preview) with actor_id = $admin->id — the real acting admin,
 *      resolved fresh — so a correctly-attributed `security_events` row
 *      (category platform_integration_oversight) exists for every
 *      leave/revoke.
 *
 *      Prompt 6 additionally fixed this on the CANONICAL path rather
 *      than leaving the compensating row above as the only correct
 *      record: logSessionAudit() now takes the acting PlatformAdmin as
 *      an explicit third argument, and every call site in this class
 *      passes $admin. Its support_access-category row is therefore now
 *      correctly attributed too, and records the session owner
 *      separately as metadata.session_owner_platform_admin_id so "who
 *      acted" and "whose session" remain two distinct, both-present
 *      facts.
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
        private readonly ProviderConnectionService $providerConnections,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

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
     * FVACC mission-wide final hardening review finding (HIGH):
     * assertCanAccessOversight()/assertCanAccessFirm() gate BOTH reads
     * and mutations in this class, so canMutate() cannot be folded into
     * either of them without also blocking ReadOnlyAuditor's legitimate
     * read access. This is the missing piece disconnectConnection()'s
     * own docblock already flagged as "a real, pre-existing gap" but
     * only closed for itself: requestSupportAccess(),
     * enterSupportAccessSession(), leaveSupportAccessSession(),
     * revokeSupportAccessSession(), requeueOutboxEvent(),
     * requeueSyncItem(), and nudgeQueue() all called only
     * assertCanAccessOversight()/assertCanAccessFirm() and never
     * consulted canMutate() at all — a PlatformAdmin holding both
     * ReadOnlyAuditor and any oversight-granting role simultaneously
     * (a supported, expected combination per this class's own
     * permissive-OR role model) could mutate through any of those 7
     * paths despite ReadOnlyAuditor's documented "never mutate"
     * guarantee. previewRetentionSweepDryRun() is deliberately NOT in
     * this list — it is a genuine dry-run preview with zero mutation
     * (frozen design §7), not a real write.
     */
    private function assertCanMutate(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canMutate($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to mutate data.');
        }
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
        $this->assertCanMutate($admin);

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
        $this->assertCanMutate($admin);

        // Gap closure #2 (frozen design §8 item 2): start() never
        // verifies the session-starter is the original requester.
        if ((int) $request->requested_by !== (int) $admin->id) {
            throw new RuntimeException('Only the platform admin who requested support access may start this session.');
        }

        $firm = Firm::query()->findOrFail($request->firm_id);

        // Prompt 6: the authorization decision and the session write now
        // happen under one lock on the request row. canStartSession()
        // enforces one-approval-one-session by checking whether a session
        // already exists for this request — evaluated outside a lock, two
        // concurrent starts could both observe "none" and both issue a
        // session, defeating exactly the invariant that check exists for.
        // The request is re-read FOR UPDATE first, so the row the decision
        // is made about is the row the session is written against.
        return $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $request): SupportAccessSession {
            return DB::transaction(function () use ($admin, $request): SupportAccessSession {
                $fresh = SupportAccessRequest::query()
                    ->where('id', $request->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Gap closure #4 (frozen design §8 item 4): canStartSession()'s
                // approval check is not auto-sequenced before start() — checked
                // fresh here, never trusted from an earlier mount()/visible()
                // -time check alone (TOCTOU discipline).
                $decision = $this->supportPolicy->canStartSession($fresh);

                if (! $decision->allowed) {
                    throw new RuntimeException($decision->reason ?? 'This support access session may not start.');
                }

                $session = $this->supportSessions->start($fresh);

                $this->supportPolicy->logSessionAudit($session, 'support_access.session_started', $admin);

                return $session;
            });
        });
    }

    public function leaveSupportAccessSession(PlatformAdmin $admin, SupportAccessSession $session): SupportAccessSession
    {
        $this->assertCanAccessOversight($admin);
        $this->assertCanMutate($admin);

        // Security review Finding 1 (CHECKPOINT_11_SECURITY_IMPLEMENTATION_REJECTED):
        // leave is self-service only (see LeaveSupportAccessSessionAction's
        // own docblock) — that invariant used to be enforced ONLY by the
        // Filament UI's own-sessions-only Select ->options() constraint,
        // never inside this chokepoint itself. Enforced here now, with the
        // same type-safe comparison style enterSupportAccessSession()
        // already uses for requested_by above.
        if ((int) $session->platform_admin_id !== (int) $admin->id) {
            throw new RuntimeException('Only the platform admin who holds this support access session may leave it.');
        }

        $firm = Firm::query()->findOrFail($session->firm_id);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm, $session): SupportAccessSession {
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

            $this->supportPolicy->logSessionAudit($ended, 'support_access.session_ended', $admin);

            // Security review Finding 2: SupportAccessPolicyService::
            // logSessionAudit() (frozen, unmodified) always attributes
            // actor_id = $session->platform_admin_id — correct for the
            // support_access-category, session-table-level bookkeeping
            // row above, but leave is self-service by construction (see
            // the ownership check above), so here that always equals the
            // real acting admin anyway. Writing the companion, correctly-
            // attributed security_events row here too keeps leave/revoke
            // symmetric and gives this firm's oversight audit trail
            // (sanitizedAuditHistoryForFirm(), category
            // platform_integration_oversight) its own explicit record of
            // the action, actor_id resolved fresh from $admin, never from
            // any cached/session-owner property.
            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.support_access_session_ended', [
                'support_access_session_id' => $ended->id,
                'support_access_session_uuid' => $ended->uuid,
            ]);

            return $ended;
        });
    }

    public function revokeSupportAccessSession(PlatformAdmin $admin, SupportAccessSession $session): SupportAccessSession
    {
        $this->assertCanAccessOversight($admin);
        $this->assertCanMutate($admin);

        $firm = Firm::query()->findOrFail($session->firm_id);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm, $session): SupportAccessSession {
            // Gap closure #3 (frozen design §8 item 3) — identical
            // discipline as leaveSupportAccessSession() above, applied
            // to revoke().
            $fresh = SupportAccessSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== SupportAccessSessionStatus::Active) {
                return $fresh;
            }

            $revoked = $this->supportSessions->revoke($fresh, $admin);

            $this->supportPolicy->logSessionAudit($revoked, 'support_access.session_revoked', $admin);

            // Security review Finding 2: RevokeSupportAccessSessionAction's
            // entire documented purpose is letting one admin end a
            // DIFFERENT admin's session (e.g. an ImplementationSpecialist
            // revoking a SupportAgent's session) — unlike leave, revoker
            // and session owner are frequently different admins here.
            // SupportAccessPolicyService::logSessionAudit() (frozen,
            // unmodified) always writes actor_id = $revoked-
            // >platform_admin_id, i.e. the ORIGINAL session holder, never
            // the admin who actually performed the revoke — misattributing
            // every cross-actor revoke's security_events row. This
            // companion row is the correctly-attributed one: actor_id =
            // $admin->id, the real acting admin, resolved fresh here, not
            // trusted from any cached property.
            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.support_access_session_revoked', [
                'support_access_session_id' => $revoked->id,
                'support_access_session_uuid' => $revoked->uuid,
                'session_owner_platform_admin_id' => $revoked->platform_admin_id,
            ]);

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
        $this->assertCanMutate($admin);

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
        $this->assertCanMutate($admin);

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
        $this->assertCanMutate($admin);

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

    /**
     * Phase 2 (FirmsVault Platform Admin Control Center, "Integration
     * Operations Center") addition. Disconnects a firm's live provider
     * connection from the platform-admin panel. Mirrors
     * requeueOutboxEvent()'s exact shape: role-ceiling authorization
     * check first, fresh connection lookup scoped to the given firm,
     * call into ProviderConnectionService::disconnect() via its new
     * admin-actor path (Phase 2 addition — see that method's own
     * docblock), then an audit event via this class's own established
     * writeOversightAuditEvent() mechanism — never a second, parallel
     * audit-write path.
     *
     * Authorization is intentionally NARROWER than every other method
     * in this class: this is the first method here to actually consult
     * PlatformStaffAccessPolicyService::canMutate() (a real, pre-existing
     * gap — canMutate() was never consulted anywhere in this class
     * before this method), and it uses the narrow
     * canManageIntegrationConnections() role ceiling
     * (SuperAdmin/PlatformAdmin only) instead of the broad
     * assertCanAccessFirm()/canAccessIntegrationOversight() gate every
     * read/requeue/nudge method above uses — mutating a firm's live
     * connection is a materially more sensitive action than reading
     * oversight data or requeuing an already-failed item. Both
     * SuperAdmin and PlatformAdmin are already unconditionally-trusted
     * ceiling roles (see UNCONDITIONALLY_TRUSTED_ROLES/
     * requiresSupportAccessSession()), so no separate governed
     * SupportAccessSession check is layered on top here — the role
     * ceiling itself already excludes every role that would otherwise
     * need one.
     *
     * The PlatformAdmin-side authorization is enforced entirely HERE,
     * in this chokepoint, before ProviderConnectionService::disconnect()
     * is ever reached — that method's own authorization checks
     * (assertCanDisconnect()) are for the FirmUser path only and are
     * deliberately skipped on the admin-actor path; it never trusts an
     * unauthenticated/unauthorized caller on its own.
     */
    public function disconnectConnection(PlatformAdmin $admin, Firm $firm, int $connectionId, string $reason): FirmIntegration
    {
        $this->assertCanManageIntegrationConnections($admin);

        return $this->tenantContext->runWithFirmContext($firm, function () use ($admin, $firm, $connectionId, $reason): FirmIntegration {
            $connection = FirmIntegration::query()
                ->where('id', $connectionId)
                ->where('firm_id', $firm->id)
                ->firstOrFail();

            $disconnected = $this->providerConnections->disconnect($connection, actorPlatformAdminId: $admin->id);

            $this->writeOversightAuditEvent($firm, $admin, 'platform_integration_oversight.connection_disconnected', [
                'firm_integration_id' => $connectionId,
                'reason' => $reason,
                'resulting_status' => $disconnected->status->value,
            ]);

            return $disconnected;
        });
    }

    /**
     * The narrow role-ceiling + canMutate() gate disconnectConnection()
     * uses instead of assertCanAccessFirm()/assertCanAccessOversight() —
     * see that method's own docblock.
     */
    private function assertCanManageIntegrationConnections(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canManageIntegrationConnections($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to manage integration connections.');
        }

        $mutateDecision = $this->accessPolicy->canMutate($admin);

        if (! $mutateDecision->allowed) {
            throw new RuntimeException($mutateDecision->reason ?? 'Not permitted to mutate data.');
        }
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
