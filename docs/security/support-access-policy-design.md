# Support Access — Required RLS/Authorization Policy Shape (Design Record)

**Status:** Design record only, recorded 2026-07-13. **Implementation is explicitly deferred** to a dedicated, reviewed task — this is Wave 1's documentation of the requirement, not its implementation.
**Tables in scope:** `support_access_requests`, `support_access_sessions`.
**Related services (current state, described below):** `app/Services/SupportAccessPolicyService.php`, `app/Services/SupportAccessSessionService.php`, `app/Services/SupportAccessRequestService.php`.

---

## Governing principle

**No broadly permissive platform-admin RLS policy is acceptable.** A policy shaped like "any platform admin may read/write any firm's support-access rows" would defeat tenant isolation for every firm at once through a single credential compromise or coding mistake, and is explicitly rejected as a design option. Support access must be a **separate, audited capability**, never a generic bypass, and normal tenant context remains the default even for platform admin database sessions.

## Required policy shape

Access to a firm's support-access data (and, by extension, whatever elevated access an active support session is meant to grant) must require **all** of the following simultaneously — not any one of them alone:

1. **Approved support request** — a `support_access_requests` row that has actually reached an approved state (standard: firm-approved; emergency: the linked high-risk change request has reached `Approved`). A merely-requested, denied, or expired request must not authorize anything.
2. **Active, non-expired support session** — a `support_access_sessions` row whose status is active and whose `expires_at` has not passed. Session validity must be re-checked against the clock, not just a status column (an already-expired-but-not-yet-marked-expired row must not authorize access).
3. **Exact target firm match** — the session must authorize access only to the one firm named on its originating request (`firm_id`), never any other firm, and never firm-wide/all-firms access.
4. **Exact support actor match** — the session must authorize access only for the specific platform admin who was granted the session, not any platform admin generally.
5. **Recorded reason/scope** — every request must carry a non-empty reason (and, for emergency access, a non-empty emergency justification), and that reason/scope must be attached to the audit trail, not just the request row.
6. **Immutable audit events** — every request/grant/denial/session-start/session-end event must be recorded in a way that cannot be quietly altered or deleted after the fact.
7. **Automatic cleanup/restoration** — once a session ends (naturally expires, is revoked, or otherwise ends), any elevated access it granted must be automatically withdrawn — there must be no manual step required to "remember" to revoke it, and no lingering broadened context afterward.
8. **Denial outside the approved session** — any access attempt that does not satisfy conditions 1–4 simultaneously must be denied by default (fail closed), not merely discouraged by application-layer convention.

## Current architecture — described as-is, for accuracy

This section describes what exists today, so that whoever implements the policy above knows exactly what is already in place versus what is still missing. All three services were read directly for this record.

- **`SupportAccessRequestService`** (`app/Services/SupportAccessRequestService.php`) is the only writer of `support_access_requests`. It enforces reason is always required (throws `InvalidArgumentException` if empty), and enforces `emergency_justification` is required specifically for `SupportAccessType::Emergency` requests. Emergency requests additionally raise a linked `high_risk_change_requests` row via the existing `HighRiskPlatformChangePolicyService`, using `HighRiskChangeType::EmergencySupportAccess` (a change type that does not require a second approver). The link is stored via the high-risk request's `metadata` JSON column (`support_access_request_id`), not a new column. `isEmergencyHighRiskApproved()` checks whether that linked high-risk request has reached `Approved`.
- **`SupportAccessPolicyService`** (`app/Services/SupportAccessPolicyService.php`) is the decision point for whether a session may start (`canStartSession()`): it denies if reason is empty, denies emergency access without `emergency_justification` or without the linked high-risk approval, and denies standard access unless the request's own status is `Approved`. It also logs "automatic notification events" (`logNotification()`, `logSessionAudit()`) as rows in the existing `security_events` table — no new/second audit mechanism was introduced, and no real email/SMS/webhook notification is ever sent (reused, generic mechanism, per this service's own docblock).
- **`SupportAccessSessionService`** (`app/Services/SupportAccessSessionService.php`) is the only writer of `support_access_sessions`. `start()` creates a session tied to the originating request's `firm_id` and the requesting platform admin's id, with `expires_at` computed from `requested_duration_minutes`. It does not itself re-check firm approval — it trusts that `SupportAccessPolicyService::canStartSession()` was already checked by the caller. `end()`/`revoke()` mark a session ended/revoked with timestamps. `isValid()` delegates to `SupportAccessSession::isCurrentlyValid()`, which independently re-checks `expires_at` rather than trusting the status column alone.

**What this current architecture already provides toward the required shape above:** requirement 1 (approval gating before session start), most of requirement 5 (reason/justification required and recorded), most of requirement 6 (`security_events` audit rows for request/grant/denial/session events), and part of requirement 2 (expiry-aware validity check via `isCurrentlyValid()`).

**What is not yet confirmed present, and must be verified or built before this design's requirements can be considered met:**
- There is no RLS policy on `support_access_requests` or `support_access_sessions` today — per the Wave 0 audit, both tables are currently uncovered tenant-owned tables (see `docs/governance/rls-gap-registry.md`, §1). All enforcement described above is application-layer (PHP service checks), not database-layer. Requirement 8 ("denial outside the approved session," fail-closed at the data layer) is not yet met at the RLS layer — only at the application layer, which is a materially weaker guarantee for a capability this sensitive.
- No mechanism was found that ties an active `SupportAccessSession` into the tenant-context/RLS session variables (e.g., `TenantContextService`'s `app.current_firm_id`) such that a platform admin's actual elevated database access is scoped to exactly the session's target firm and actor at the RLS layer. Today the session rows are bookkeeping/audit records; whether and how they gate a platform admin's *actual* query access to a firm's other tables is not established by the three services reviewed here.
- Requirement 7 (automatic cleanup/restoration) exists for the session's own status/timestamps (`end()`, `revoke()`), but whether any elevated access an active session might grant elsewhere in the system is automatically withdrawn on expiry (as opposed to only on explicit `end()`/`revoke()` calls) was not confirmed present.

**This gap between the current application-layer bookkeeping and the fully required database-layer enforcement is exactly why implementation is deferred to a dedicated, reviewed task** — the existing architecture may prove sufficient as a foundation, or may prove insufficient and require restructuring (e.g. wiring active-session state into `TenantContextService`, or adding an RLS policy on the two tables themselves that checks for an active, non-expired, exactly-matching session). That determination is explicitly left to the dedicated implementation task, not resolved here.

## Non-goals of this document

This document does not implement an RLS policy, does not modify any of the three services above, and does not decide the exact SQL predicate or context-wiring mechanism. It exists to register the required shape and the current-state gap so a future dedicated task can implement (or first further investigate) with full context.
