# Runbook: Support Access Request

## Symptom / use case

A SupportAgent needs governed, time-bounded access to a specific firm's integration data (beyond the always-visible, sanitized aggregate overview) to investigate or assist with an issue.

## Real source involved

`SupportAccessRequestService::request()` / `approve()` / `deny()` / `expire()`, `SupportAccessPolicyService::canStartSession()` / `logNotification()`, `SupportAccessSessionService::start()`, `App\Services\PlatformFirmIntegrationBoundedAccessService::requestSupportAccess()` / `enterSupportAccessSession()`.

## Access model recap

Only **SupportAgent** needs a governed session for per-firm access — SuperAdmin, PlatformAdmin, and ImplementationSpecialist are unconditionally-trusted ceiling roles that pass the coarse `canAccessIntegrationOversight()` gate without an additional session requirement (`PlatformFirmIntegrationBoundedAccessService::requiresSupportAccessSession()`). The always-visible, sanitized aggregate overview (`PlatformIntegrationOverviewPage`) never requires a support-access grant for anyone, since it never exposes per-firm identifiable mutating capability.

## Required role

SupportAgent (the role this entire workflow exists for). Approval authority sits with the firm side per the existing `SupportAccessRequestService::approve()`/`deny()` mechanism — this framework does not change who approves, only how the integration domain consumes an approved session.

## Approved interface

`PlatformFirmIntegrationBoundedAccessService::requestSupportAccess(...)` — this is the **integration-domain-specific entry point**, and it closes a governance gap the pre-existing `SupportAccessRequestService::request()` had on its own: the pre-existing method never called `logNotification()` itself. `requestSupportAccess()` invokes both `request()` **and** `logNotification()` as two explicit sequential calls, so a support-access request initiated through the integration oversight surface always produces the expected notification, not silently skips it.

## Steps

1. SupportAgent initiates a request via `PlatformFirmIntegrationBoundedAccessService::requestSupportAccess()`, scoped to the specific target firm.
2. The request follows the existing firm-side approval workflow (`SupportAccessRequestService::approve()`/`deny()`) — unchanged by this framework.
3. Once approved, the SupportAgent enters the session via `PlatformFirmIntegrationBoundedAccessService::enterSupportAccessSession(PlatformAdmin $admin, SupportAccessRequest $request)`. This method additionally verifies the session-starter is the original requester (`$request->requested_by === $admin->id`) before calling the underlying `SupportAccessSessionService::start()` — closing a second governance gap: the pre-existing session service performed no such check on its own.
4. With an active, firm-scoped session, the SupportAgent may now perform the per-firm reads/actions this framework's platform-oversight surfaces expose (subject to whatever additional per-action checks apply — e.g. requeue actions still require their own `reasonCode` and produce their own audit trail).
5. When finished, see [support-session-revoke.md](support-session-revoke.md) for ending or revoking the session.

## Prohibited actions

Bypassing the request/approval workflow — there is no "emergency direct access" path in this framework specific to integrations; any such path would come from the pre-existing, unmodified `SupportAccessRequestService`/`SupportAccessPolicyService` mechanisms, not from anything added here. Starting a session for a request one did not personally make (structurally prevented by `enterSupportAccessSession()`'s check, but do not attempt to work around it).

## Evidence to capture

Requesting SupportAgent id, target firm id, request/approval timestamps, session start timestamp. All captured automatically by the underlying services — no separate manual log is needed.

## Escalation condition

A request denied by the firm side that the SupportAgent believes should be escalated (e.g. a genuine emergency) should go through whatever emergency-access escalation process the pre-existing support-access system defines — not worked around at the integration-domain layer.

## Recovery verification

Session shows as active and correctly scoped to the intended firm; the requesting agent's subsequent per-firm reads succeed and are attributed to them via the `platform_integration_oversight` security-events category (see [operations-superadmin.md](../operations-superadmin.md) §5).
