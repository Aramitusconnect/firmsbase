# Runbook: Support Session Revoke

## Symptom / use case

An active `SupportAccessSession` for the integration domain needs to be ended — either normally (the SupportAgent is done) or forcibly (revoked by a platform admin, e.g. because the access is no longer appropriate or was granted in error).

## Real source involved

`SupportAccessSessionService::end()` / `revoke()`, `App\Services\PlatformFirmIntegrationBoundedAccessService::leaveSupportAccessSession()` / `revokeSupportAccessSession()`.

## Read this before investigating any revoke event: attribution

Two `security_events` categories exist, and they are **not interchangeable**:

- **`platform_integration_oversight`** (`PlatformFirmIntegrationBoundedAccessService::SECURITY_EVENT_CATEGORY`) — the correct, authoritative category for this domain's revoke attribution.
- **`support_access`** (written by the pre-existing `SupportAccessPolicyService::logSessionAudit()`) — carries a **known, disclosed misattribution bug for cross-actor revokes**: it names the session *owner* as the actor, not the actual revoker. If a platform admin revokes a SupportAgent's session, the `support_access`-category row will misleadingly show the SupportAgent as the actor of their own revocation.

**Always use `platform_integration_oversight` for attribution when investigating who actually revoked a session.** Do not rely on `support_access` category rows for this — they will give you the wrong actor for exactly the cross-actor case you're most likely investigating (someone other than the session owner ending it).

## Required role

**Normal end (`leaveSupportAccessSession()`)**: the session's own SupportAgent, ending their own session. **Forced revoke (`revokeSupportAccessSession()`)**: SuperAdmin/PlatformAdmin/ImplementationSpecialist — a higher-trust action than a self-initiated end, since it terminates another actor's active access.

## Approved interface

`PlatformFirmIntegrationBoundedAccessService::leaveSupportAccessSession(PlatformAdmin $admin, SupportAccessSession $session)` and `::revokeSupportAccessSession(PlatformAdmin $admin, SupportAccessSession $session)`. Both add an **idempotency guard** the pre-existing `SupportAccessSessionService::end()`/`revoke()` methods lacked on their own — calling either of the bounded-access wrapper methods twice on an already-ended/revoked session is safe and does not produce a duplicate or erroneous state transition.

## Steps

1. Determine whether this is a normal end (the SupportAgent's own session, they're done) or a forced revoke (someone else terminating it).
2. Call the appropriate method — `leaveSupportAccessSession()` for normal end, `revokeSupportAccessSession()` for forced revoke — through `PlatformFirmIntegrationBoundedAccessService`, not the underlying `SupportAccessSessionService` directly, to get the idempotency guard and the correctly-attributed `platform_integration_oversight` audit row.
3. If investigating a past revoke event (who revoked, when, why), query `platform_integration_oversight`-category `security_events` rows, not `support_access`-category rows, for accurate actor attribution.
4. Confirm the session's status reflects the intended end state (ended vs. revoked — these may carry different downstream meaning depending on how the session-status field is consumed elsewhere).

## Prohibited actions

Calling `SupportAccessSessionService::end()`/`revoke()` directly from integration-domain code instead of going through `PlatformFirmIntegrationBoundedAccessService`'s wrapper methods — doing so would lose both the idempotency guard and the correctly-attributed audit category. Relying on `support_access`-category attribution for a cross-actor revoke investigation (see above).

## Evidence to capture

Session id, firm id, session owner (SupportAgent), revoking/ending actor, `platform_integration_oversight` event type and timestamp.

## Escalation condition

A forced revoke performed without a documented reason, or a pattern of sessions being revoked shortly after being granted, may indicate either an access-granting process issue or a genuine security concern about the SupportAgent's access — escalate to whoever owns the support-access governance process if the pattern looks anomalous.

## Recovery verification

Session's status correctly reflects ended/revoked; a subsequent attempt to use the session for a per-firm action is correctly denied; the `platform_integration_oversight` audit trail correctly attributes the actor who performed the revoke.
